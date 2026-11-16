<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Application\Messaging\IncomingReply;
use App\Application\Messaging\MessageFactory;
use App\Application\Shared\SagaSettings;
use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\FileName;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\VideoId;
use App\Infrastructure\Messaging\S3EventParser;
use App\Infrastructure\Support\RamseyUuidV7Generator;
use App\Infrastructure\Support\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Contracts;

/** Produtor estrito (o que o video-api envia valida nos schemas) e consumidor tolerante (lê os exemplos). */
final class MessagesContractTest extends TestCase
{
    private function cenario(): array
    {
        $now = new \DateTimeImmutable;
        $video = Video::create(new VideoId('0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f'), new Owner('0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6c', 'ana@exemplo.com', 'Ana'),
            new FileName('Aula 01.MOV'), new FileSize(10), 'video/quicktime', $now);
        $saga = ProcessingSaga::start('0192f1c4-0000-7000-8000-000000000001', $video->id, '0192f1c4-0000-7000-8000-0000000000aa', $now);
        $factory = new MessageFactory(new RamseyUuidV7Generator, new SystemClock, new SagaSettings(bucket: 'fiapx-prod-videos'));

        return [$factory, $saga, $video, $now];
    }

    public function test_process_video_e_cleanup_artifacts(): void
    {
        [$factory, $saga, $video] = $this->cenario();
        self::assertSame([], Contracts::errors($factory->processVideo($saga, $video)->envelope, 'process-video.v1.schema.json'));
        self::assertSame([], Contracts::errors($factory->cleanupArtifacts($saga)->envelope, 'cleanup-artifacts.v1.schema.json'));
    }

    public function test_send_notification_de_video(): void
    {
        [$factory, $saga, $video, $now] = $this->cenario();
        self::assertSame([], Contracts::errors($factory->videoCompleted($saga, $video)->envelope, 'send-notification.v1.schema.json'));
        $video->markUploaded($now);
        $video->fail(FailureReason::InvalidUpload, $now);
        $msg = $factory->videoFailed($saga, $video)->envelope;
        self::assertSame([], Contracts::errors($msg, 'send-notification.v1.schema.json'));
        self::assertSame('INVALID_UPLOAD', $msg['payload']['reason']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function respostas(): iterable
    {
        yield 'ProcessingStarted' => ['processing-started.v1.json', 'ProcessingStarted'];
        yield 'VideoProcessed' => ['video-processed.v1.json', 'VideoProcessed'];
        yield 'VideoProcessingFailed' => ['video-processing-failed.v1.json', 'VideoProcessingFailed'];
        yield 'ArtifactsCleaned' => ['artifacts-cleaned.v1.json', 'ArtifactsCleaned'];
        yield 'NotificationSent' => ['notification-sent.v1.json', 'NotificationSent'];
        yield 'NotificationFailed' => ['notification-failed.v1.json', 'NotificationFailed'];
    }

    #[DataProvider('respostas')]
    public function test_consome_os_exemplos_de_resposta(string $arquivo, string $tipo): void
    {
        $exemplo = Contracts::example($arquivo);
        $exemplo['campo_futuro'] = 'ignorado';
        $exemplo['payload']['outro_campo'] = 1;
        $reply = IncomingReply::fromEnvelope($exemplo);
        self::assertSame($tipo, $reply->type);
        self::assertSame($exemplo['saga_id'], $reply->sagaId);
    }

    public function test_consome_o_exemplo_do_evento_do_s3(): void
    {
        $events = (new S3EventParser)->parse('0192F1C4-0000-7000-8000-0000000000AA', json_encode(Contracts::example('s3-object-created.json'), JSON_THROW_ON_ERROR));
        self::assertCount(1, $events);
        self::assertSame('uploads/0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f.mp4', $events[0]->key);
        self::assertSame('0192f1c4-0000-7000-8000-0000000000aa', $events[0]->eventId);
        self::assertSame(73400320, $events[0]->sizeBytes);
    }

    public function test_s3_test_event_e_descartado_e_chave_codificada_e_decodificada(): void
    {
        $p = new S3EventParser;
        self::assertSame([], $p->parse('0192f1c4-0000-7000-8000-0000000000aa', '{"Service":"Amazon S3","Event":"s3:TestEvent","Bucket":"b"}'));
        $body = ['Records' => [
            ['eventSource' => 'aws:s3', 'eventName' => 'ObjectCreated:Post', 's3' => ['bucket' => ['name' => 'b'], 'object' => ['key' => 'uploads/a%20b+c.mp4', 'size' => 1]]],
            ['eventSource' => 'aws:s3', 'eventName' => 'ObjectRemoved:Delete', 's3' => ['bucket' => ['name' => 'b'], 'object' => ['key' => 'x', 'size' => 1]]],
            ['eventSource' => 'aws:s3', 'eventName' => 'ObjectCreated:Put', 's3' => ['bucket' => ['name' => 'b'], 'object' => ['key' => 'uploads/z.mp4', 'size' => 2]]],
        ]];
        $events = $p->parse('0192f1c4-0000-7000-8000-0000000000aa', (string) json_encode($body));
        self::assertSame(['uploads/a b c.mp4', 'uploads/z.mp4'], array_map(fn ($e) => $e->key, $events));
        self::assertNotSame($events[0]->eventId, $events[1]->eventId);
    }

    public function test_corpo_que_nao_e_evento_do_s3(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new S3EventParser)->parse('x', '{"foo":1}');
    }

    public function test_corpo_que_nao_e_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new S3EventParser)->parse('x', 'nada');
    }
}
