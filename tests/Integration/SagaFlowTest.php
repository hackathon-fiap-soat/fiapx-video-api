<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Shared\Ports\ObjectStorage;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Infrastructure\Config\ParameterResolver;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\Support\JwtFactory;
use Tests\TestCase;

/**
 * Integração com o ambiente local real (MySQL + LocalStack do fiapx-infra/local): POST pré-assinado de
 * verdade no S3, evento do S3 na fila, saga:consume, outbox:relay, processor:simulate e download.
 *
 * Os dados ficam gravados (sem transação), porque os comandos e o S3 enxergam o banco por fora; cada teste
 * apaga o que criou. Se os workers do docker-compose estiverem de pé, eles podem tratar as mensagens antes;
 * o teste só espera o resultado final.
 */
final class SagaFlowTest extends TestCase
{
    private const USER = '0192f1c4-6a00-7b00-8c00-00000000abcd';

    /** @var list<string> */
    private array $videos = [];

    protected function setUp(): void
    {
        parent::setUp();
        JwtFactory::install($this->app);
    }

    protected function tearDown(): void
    {
        DB::table('processing_sagas')->whereIn('video_id', $this->videos)->delete();
        DB::table('videos')->whereIn('id', $this->videos)->delete();
        DB::table('processing_quotas')->where('user_id', self::USER)->delete();
        parent::tearDown();
    }

    /** Pede o upload à API e envia o arquivo ao S3 como o navegador faria (campos + arquivo por último). */
    private function upload(string $filename, string $content): string
    {
        $r = $this->postJson('/api/videos', ['filename' => $filename, 'size_bytes' => strlen($content), 'content_type' => 'video/mp4'],
            ['Authorization' => 'Bearer '.JwtFactory::token(self::USER)])->assertCreated();
        $this->videos[] = $r->json('id');
        $post = Http::asMultipart();
        foreach ($r->json('upload.fields') as $k => $v) {
            $post = $post->attach($k, $v);
        }
        $s3 = $post->attach('file', $content, $filename)->post($r->json('upload.url'));
        self::assertSame(204, $s3->status(), 'o S3 recusou o POST pré-assinado: '.$s3->body());

        return $r->json('id');
    }

    private function statusDoVideo(string $id): string
    {
        return (string) DB::table('videos')->where('id', $id)->value('status');
    }

    /** Roda os workers uma vez por volta até os vídeos chegarem a um status final. */
    private function rodarSagaAte(array $ids, array $finais = ['COMPLETED', 'FAILED']): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->artisan('saga:consume', ['--once' => true, '--wait' => 1]);
            $this->artisan('outbox:relay', ['--once' => true]);
            $this->artisan('processor:simulate', ['--once' => true]);
            if (count(array_filter($ids, fn ($id) => in_array($this->statusDoVideo($id), $finais, true))) === count($ids)) {
                return;
            }
        }
        self::fail('a saga não terminou: '.implode(', ', array_map(fn ($id) => $this->statusDoVideo($id), $ids)));
    }

    public function test_upload_real_processa_e_libera_o_download(): void
    {
        $ok = $this->upload('aula.mp4', random_bytes(4096));
        $ruim = $this->upload('corrompido.mp4', 'x');
        $this->rodarSagaAte([$ok, $ruim]);

        self::assertSame('COMPLETED', $this->statusDoVideo($ok));
        $saga = DB::table('processing_sagas')->where('video_id', $ok)->first();
        self::assertSame('COMPLETED', $saga->state);
        self::assertSame(['STARTED', 'UPLOAD_VALIDATED', 'QUOTA_RESERVED', 'PROCESSING_REQUESTED', 'PROCESSING', 'COMPLETED'], array_column(json_decode($saga->history, true), 'state'));
        self::assertSame(1, DB::table('outbox_messages')->where('payload->video_id', $ok)->where('type', 'SendNotification')->whereNotNull('published_at')->count());

        $url = $this->getJson("/api/videos/{$ok}/download", ['Authorization' => 'Bearer '.JwtFactory::token(self::USER)])->assertOk()->json('url');
        $zip = Http::get($url);
        self::assertSame(200, $zip->status());
        self::assertStringStartsWith("PK\x03\x04", $zip->body());

        self::assertSame('FAILED', $this->statusDoVideo($ruim));
        self::assertSame('FFMPEG_ERROR', DB::table('videos')->where('id', $ruim)->value('failure_code'));
        self::assertSame('COMPENSATED', DB::table('processing_sagas')->where('video_id', $ruim)->value('state'));
        self::assertSame(0, (int) DB::table('processing_quotas')->where('user_id', self::USER)->value('in_progress'), 'cota liberada nos dois casos');
    }

    public function test_upload_maior_que_o_declarado_e_recusado_pelo_s3(): void
    {
        $r = $this->postJson('/api/videos', ['filename' => 'a.mp4', 'size_bytes' => 10, 'content_type' => 'video/mp4'],
            ['Authorization' => 'Bearer '.JwtFactory::token(self::USER)])->assertCreated();
        $this->videos[] = $r->json('id');
        $post = Http::asMultipart();
        foreach ($r->json('upload.fields') as $k => $v) {
            $post = $post->attach($k, $v);
        }
        self::assertSame(400, $post->attach('file', str_repeat('x', 11), 'a.mp4')->post($r->json('upload.url'))->status());
    }

    public function test_storage_head_download_e_delete(): void
    {
        /** @var ObjectStorage $storage */
        $storage = $this->app->make(ObjectStorage::class);
        $s3 = $this->app->make(S3Client::class);
        $bucket = $this->app->make(ParameterResolver::class)->get('bucket');
        $key = StorageKey::fromString('results/0192f1c4-0000-7000-8000-00000000beef/frames.zip');
        $s3->putObject(['Bucket' => $bucket, 'Key' => $key->value, 'Body' => 'zip', 'ContentType' => 'application/zip']);

        self::assertSame(3, $storage->head($key)?->sizeBytes);
        self::assertSame('zip', Http::get($storage->presignDownload($key, 60)->url)->body());
        $storage->delete($key);
        self::assertNull($storage->head($key));
        $storage->delete($key);
    }

    public function test_relay_publica_na_fila_certa_e_marca_published_at(): void
    {
        $id = (string) Uuid::uuid7();
        $envelope = ['message_id' => $id, 'type' => 'SendNotification', 'version' => 1, 'saga_id' => $id, 'video_id' => $id, 'correlation_id' => $id, 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'payload' => []];
        DB::table('outbox_messages')->insert(['id' => $id, 'destination' => 'notification-commands', 'type' => 'SendNotification', 'payload' => json_encode($envelope), 'created_at' => now()]);
        try {
            $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
            self::assertNotNull(DB::table('outbox_messages')->where('id', $id)->value('published_at'));

            $sqs = $this->app->make(SqsClient::class);
            $queue = $this->app->make(ParameterResolver::class)->get('notification_commands_queue_url');
            $found = false;
            for ($i = 0; $i < 5 && ! $found; $i++) {
                foreach ($sqs->receiveMessage(['QueueUrl' => $queue, 'MaxNumberOfMessages' => 10, 'WaitTimeSeconds' => 1])['Messages'] ?? [] as $m) {
                    $sqs->deleteMessage(['QueueUrl' => $queue, 'ReceiptHandle' => $m['ReceiptHandle']]);
                    $found = $found || json_decode($m['Body'], true)['message_id'] === $id;
                }
            }
            self::assertTrue($found, 'mensagem não chegou à fila notification-commands');
        } finally {
            DB::table('outbox_messages')->where('id', $id)->delete();
        }
    }

    public function test_comandos_agendados(): void
    {
        $this->artisan('saga:check-timeouts')->assertSuccessful();
        $this->artisan('videos:expire-pending')->assertSuccessful();
        $this->artisan('messaging:prune')->assertSuccessful();
    }
}
