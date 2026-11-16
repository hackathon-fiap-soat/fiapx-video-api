<?php

declare(strict_types=1);

namespace Tests\Behat;

use App\Application\Messaging\IncomingReply;
use App\Application\Saga\DTOs\UploadedObjectEvent;
use App\Application\Saga\UseCases\CheckSagaTimeoutsUseCase;
use App\Application\Saga\UseCases\HandleSagaReplyUseCase;
use App\Application\Saga\UseCases\StartProcessingSagaUseCase;
use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Shared\SagaSettings;
use App\Application\Video\DTOs\StoredObject;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FileName;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\VideoId;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Messaging\MessageQueue;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Tests\Support\InMemoryObjectStorage;
use Tests\Support\InMemoryQueue;

/**
 * Passos da SAGA contra o MySQL real (cada cenário numa transação desfeita no fim), com S3 e SQS em memória.
 * Usa os mesmos casos de uso e o mesmo consumidor (saga:consume) da aplicação.
 */
final class SagaContext implements Context
{
    private Application $app;

    private InMemoryObjectStorage $storage;

    private InMemoryQueue $queue;

    private string $videoId = '';

    private string $userId = '';

    private string $sagaId = '';

    /** @var array<string, mixed> */
    private array $snapshot = [];

    /** @var array<string, mixed>|null */
    private ?array $lastReply = null;

    #[BeforeScenario]
    public function boot(): void
    {
        $this->app = require __DIR__.'/../../bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $this->storage = new InMemoryObjectStorage;
        $this->queue = new InMemoryQueue;
        $this->app->instance(ObjectStorage::class, $this->storage);
        $this->app->instance(MessageQueue::class, $this->queue);
        DB::beginTransaction();
    }

    #[AfterScenario]
    public function rollback(): void
    {
        DB::rollBack();
    }

    // --- Dado ------------------------------------------------------------------------------------------

    #[Given('um vídeo :name enviado por :email com cota livre')]
    public function videoEnviado(string $name, string $email): void
    {
        $this->novoVideo($name, $email, 2048);
    }

    #[Given('um vídeo em processamento')]
    public function videoEmProcessamento(): void
    {
        $this->novoVideo('aula.mp4', 'ana@exemplo.com', 2048);
        $this->eventoDeUpload();
        $this->responder('ProcessingStarted', ['started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'worker_id' => 'w-1']);
        Assert::assertSame('PROCESSING', $this->video()->status);
    }

    #[Given('um vídeo em :status com prazo vencido')]
    public function videoComPrazoVencido(string $status): void
    {
        $this->videoEmProcessamento();
        Assert::assertSame($status, $this->video()->status);
        DB::table('processing_sagas')->where('id', $this->sagaId)->update(['deadline_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
    }

    #[Given('um usuário com :n vídeos em processamento')]
    public function usuarioNoLimite(int $n): void
    {
        $this->novoVideo('sexto.mp4', 'ana@exemplo.com', 2048);
        DB::table('processing_quotas')->updateOrInsert(['user_id' => $this->userId], ['in_progress' => $n, 'max_parallel' => 5]);
    }

    #[Given('um evento de upload de um arquivo de :mb MB')]
    public function uploadGrande(int $mb): void
    {
        $this->novoVideo('grande.mp4', 'ana@exemplo.com', 1024);
        // O arquivo no bucket é maior que o permitido (o S3 já teria recusado; a saga confere de novo).
        $this->storage->objects["uploads/{$this->videoId}.mp4"] = new StoredObject($mb * 1024 * 1024, 'video/mp4');
    }

    #[Given('que a resposta :type com message_id :id já foi processada')]
    public function respostaJaProcessada(string $type, string $id): void
    {
        $this->videoEmProcessamento();
        $this->responder($type, $this->payloadDe($type), $this->idDe($id));
        $this->snapshot = $this->estado();
    }

    #[Given('uma saga em :state')]
    public function sagaEm(string $state): void
    {
        $this->videoEmProcessamento();
        $this->responder('VideoProcessingFailed', ['error_code' => 'FFMPEG_ERROR', 'error_message' => 'x', 'attempt' => 3]);
        Assert::assertSame($state, $this->saga()->state);
    }

    // --- Quando ----------------------------------------------------------------------------------------

    #[When('o evento de upload chegar')]
    #[When('a saga validar o upload')]
    #[When('o evento de upload de um sexto vídeo chegar')]
    public function eventoDeUpload(): void
    {
        $this->app->make(StartProcessingSagaUseCase::class)->execute(
            new UploadedObjectEvent(Uuid::uuid4()->toString(), $this->app->make(SagaSettings::class)->bucket, "uploads/{$this->videoId}.mp4", 2048),
        );
        $this->sagaId = (string) DB::table('processing_sagas')->where('video_id', $this->videoId)->value('id');
    }

    #[When('o processador responder :first e depois :second com :frames frames')]
    public function processadorResponde(string $first, string $second, int $frames): void
    {
        $this->responder($first, $this->payloadDe($first));
        $this->responder($second, ['zip_key' => "results/{$this->videoId}/frames.zip", 'frame_count' => $frames, 'zip_size_bytes' => 1, 'duration_ms' => 1]);
    }

    #[When('o processador responder :type com :code')]
    public function processadorFalha(string $type, string $code): void
    {
        $this->responder($type, ['error_code' => $code, 'error_message' => 'ffmpeg saiu com código 1', 'attempt' => 3]);
    }

    #[When('a verificação de timeouts rodar')]
    public function verificarTimeouts(): void
    {
        $useCase = $this->app->make(CheckSagaTimeoutsUseCase::class);
        Assert::assertContains($this->sagaId, $useCase->expiredSagaIds());
        Assert::assertTrue($useCase->timeOut($this->sagaId));
    }

    #[When('a mesma mensagem chegar de novo')]
    public function mesmaMensagem(): void
    {
        Assert::assertNotNull($this->lastReply);
        $outcome = $this->app->make(HandleSagaReplyUseCase::class)->execute(IncomingReply::fromEnvelope($this->lastReply));
        Assert::assertSame('duplicate', $outcome->value);
    }

    /** Passa pelo consumidor real (saga:consume), que decide descartar e confirmar a mensagem. */
    #[When('chegar um :type atrasado')]
    public function respostaAtrasada(string $type): void
    {
        Log::spy();
        $url = $this->app->make(ParameterResolver::class)->get('saga_replies_queue_url');
        $this->queue->push($url, (string) json_encode($this->envelope($type, $this->payloadDe($type))));
        Artisan::call('saga:consume', ['--once' => true, '--wait' => 0]);
        Assert::assertSame(0, $this->queue->pending($url), 'a mensagem deveria ser confirmada (sem DLQ)');
    }

    // --- Então -----------------------------------------------------------------------------------------

    #[Then('o vídeo fica :status com :frames frames')]
    public function videoComFrames(string $status, int $frames): void
    {
        Assert::assertSame($status, $this->video()->status);
        Assert::assertSame($frames, (int) $this->video()->frame_count);
    }

    #[Then('o vídeo fica :status com motivo :code')]
    public function videoComMotivo(string $status, string $code): void
    {
        Assert::assertSame($status, $this->video()->status);
        Assert::assertSame($code, $this->video()->failure_code);
        Assert::assertSame('COMPENSATED', $this->saga()->state);
        Assert::assertSame(1, $this->outbox('SendNotification', 'VIDEO_FAILED'));
    }

    #[Then('a cota de :email volta a :n em uso')]
    public function cotaDoUsuario(string $email, int $n): void
    {
        Assert::assertSame($this->idDe($email), $this->userId);
        Assert::assertSame($n, $this->emUso());
    }

    #[Then('a saga envia :type')]
    public function sagaEnvia(string $type): void
    {
        Assert::assertSame(1, $this->outbox($type));
    }

    #[Then('ao receber :type o vídeo fica :status com motivo :code')]
    public function aoReceber(string $type, string $status, string $code): void
    {
        $this->responder($type, ['deleted_objects' => 2]);
        $this->videoComMotivo($status, $code);
    }

    #[Then('a cota é liberada')]
    public function cotaLiberada(): void
    {
        Assert::assertSame(0, $this->emUso());
    }

    #[Then('um :type com template :template é gravado na outbox')]
    public function notificacaoNaOutbox(string $type, string $template): void
    {
        Assert::assertSame(1, $this->outbox($type, $template));
    }

    #[Then('a saga entra em :state e envia :type')]
    public function sagaEntraEm(string $state, string $type): void
    {
        Assert::assertSame($state, $this->saga()->state);
        $history = json_decode($this->saga()->history, true);
        Assert::assertSame('PROCESSING_TIMEOUT', end($history)['detail'] ?? null);
        Assert::assertSame(1, $this->outbox($type));
        Assert::assertSame('PROCESSING', $this->video()->status, 'o vídeo mantém o status até a compensação terminar');
    }

    #[Then('o arquivo enviado é apagado')]
    public function arquivoApagado(): void
    {
        Assert::assertContains("uploads/{$this->videoId}.mp4", $this->storage->deleted);
        Assert::assertSame(5, $this->emUso(), 'nada foi reservado');
    }

    #[Then('nada muda no vídeo, na saga e na cota')]
    public function nadaMuda(): void
    {
        Assert::assertSame($this->snapshot, $this->estado());
    }

    #[Then('a resposta é descartada com log de warning')]
    public function descartadaComWarning(): void
    {
        Log::getFacadeRoot()->shouldHaveReceived('warning')->withArgs(fn ($msg) => $msg === 'resposta fora de ordem descartada')->once();
    }

    #[Then('a saga continua em :state')]
    public function sagaContinua(string $state): void
    {
        Assert::assertSame($state, $this->saga()->state);
    }

    // --- Apoio -----------------------------------------------------------------------------------------

    private function novoVideo(string $name, string $email, int $bytes): void
    {
        $this->userId = $this->idDe($email);
        $this->videoId = Uuid::uuid7()->toString();
        $video = Video::create(new VideoId($this->videoId), new Owner($this->userId, $email, 'Ana'), new FileName($name), new FileSize($bytes), 'video/mp4', new \DateTimeImmutable);
        $this->app->make(VideoRepositoryInterface::class)->add($video);
        $this->storage->objects["uploads/{$this->videoId}.mp4"] = new StoredObject($bytes, 'video/mp4');
    }

    /** UUID estável derivado de um texto do cenário (e-mail, "X"...). */
    private function idDe(string $text): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'fiapx-bdd:'.$text)->toString();
    }

    /** @return array<string, mixed> */
    private function payloadDe(string $type): array
    {
        return match ($type) {
            'ProcessingStarted' => ['started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'worker_id' => 'w-1'],
            'VideoProcessed' => ['zip_key' => "results/{$this->videoId}/frames.zip", 'frame_count' => 10, 'zip_size_bytes' => 1, 'duration_ms' => 1],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(string $type, array $payload, ?string $messageId = null): array
    {
        return [
            'message_id' => $messageId ?? Uuid::uuid7()->toString(), 'type' => $type, 'version' => 1,
            'saga_id' => $this->sagaId, 'video_id' => $this->videoId, 'correlation_id' => $this->saga()->correlation_id,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function responder(string $type, array $payload, ?string $messageId = null): void
    {
        $this->lastReply = $this->envelope($type, $payload, $messageId);
        $this->app->make(HandleSagaReplyUseCase::class)->execute(IncomingReply::fromEnvelope($this->lastReply));
    }

    private function video(): object
    {
        return DB::table('videos')->where('id', $this->videoId)->first();
    }

    private function saga(): object
    {
        return DB::table('processing_sagas')->where('video_id', $this->videoId)->first();
    }

    private function emUso(): int
    {
        return (int) DB::table('processing_quotas')->where('user_id', $this->userId)->value('in_progress');
    }

    private function outbox(string $type, ?string $template = null): int
    {
        return DB::table('outbox_messages')->where('type', $type)->where('payload->video_id', $this->videoId)
            ->when($template !== null, fn ($q) => $q->where('payload->payload->template', $template))->count();
    }

    /** @return array<string, mixed> */
    private function estado(): array
    {
        return [
            'video' => (array) $this->video(),
            'saga' => (array) $this->saga(),
            'quota' => $this->emUso(),
            'outbox' => DB::table('outbox_messages')->where('payload->video_id', $this->videoId)->count(),
        ];
    }
}
