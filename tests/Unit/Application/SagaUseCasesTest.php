<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Messaging\IncomingReply;
use App\Application\Saga\DTOs\SagaOutcome;
use App\Application\Saga\DTOs\UploadedObjectEvent;
use App\Application\Saga\UseCases\CheckSagaTimeoutsUseCase;
use App\Application\Saga\UseCases\HandleSagaReplyUseCase;
use App\Application\Saga\UseCases\PruneMessagingUseCase;
use App\Application\Saga\UseCases\StartProcessingSagaUseCase;
use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Shared\Ports\OutboxHousekeeping;
use App\Application\Shared\SagaSettings;
use App\Application\Video\DTOs\StoredObject;
use App\Domain\Quota\Entities\ProcessingQuota;
use App\Domain\Quota\Interfaces\ProcessingQuotaRepositoryInterface;
use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\Exceptions\InvalidSagaTransition;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Saga\ValueObjects\SagaState;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\VideoStatus;
use Mockery;
use Mockery\MockInterface;

final class SagaUseCasesTest extends ApplicationTestCase
{
    private VideoRepositoryInterface&MockInterface $videos;

    private ProcessingSagaRepositoryInterface&MockInterface $sagas;

    private ProcessingQuotaRepositoryInterface&MockInterface $quotas;

    private ObjectStorage&MockInterface $storage;

    private ProcessingQuota $quota;

    private ?ProcessingSaga $sagaGravada = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->videos = Mockery::mock(VideoRepositoryInterface::class);
        $this->sagas = Mockery::mock(ProcessingSagaRepositoryInterface::class);
        $this->quotas = Mockery::mock(ProcessingQuotaRepositoryInterface::class);
        $this->storage = Mockery::mock(ObjectStorage::class);
        $this->quota = ProcessingQuota::forNewUser(self::USER);
        $this->quotas->allows('lockForUser')->andReturnUsing(fn () => $this->quota);
        $this->quotas->allows('save');
        $this->videos->allows('save');
        $this->sagas->allows('add')->andReturnUsing(function (ProcessingSaga $s): void {
            $this->sagaGravada = $s;
        });
        $this->sagas->allows('save');
        $this->deduplicator->allows('register')->andReturnTrue()->byDefault();
    }

    private function start(?StoredObject $head, ?string $key = null): SagaOutcome
    {
        $this->storage->allows('head')->andReturn($head);

        return (new StartProcessingSagaUseCase($this->videos, $this->sagas, $this->quotas, $this->storage, $this->commands, $this->deduplicator, $this->transactions, $this->messages(), $this->ids, $this->clock, $this->settings))
            ->execute(new UploadedObjectEvent(self::EVENT, 'fiapx-local-videos', $key ?? 'uploads/'.self::VIDEO.'.mp4', 1000));
    }

    private function reply(): HandleSagaReplyUseCase
    {
        return new HandleSagaReplyUseCase($this->sagas, $this->videos, $this->quotas, $this->commands, $this->deduplicator, $this->transactions, $this->messages(), $this->clock, $this->settings);
    }

    private function envelope(string $type, array $payload, string $id = '0192f1c4-2222-7000-8000-000000000001'): IncomingReply
    {
        return IncomingReply::fromEnvelope(['message_id' => $id, 'type' => $type, 'version' => 1, 'saga_id' => self::SAGA,
            'video_id' => self::VIDEO, 'correlation_id' => self::EVENT, 'occurred_at' => '2026-11-16T12:00:00Z', 'payload' => $payload, 'campo_novo' => 'ignorado']);
    }

    public function test_inicio_valida_reserva_cota_e_grava_process_video(): void
    {
        $video = $this->video();
        $this->videos->expects('findForUpdate')->andReturn($video);
        $this->sagas->expects('findByVideo')->andReturnNull();

        self::assertSame(SagaOutcome::Started, $this->start(new StoredObject(1000, 'video/mp4')));
        self::assertSame(VideoStatus::Queued, $video->status());
        self::assertSame(1, $this->quota->inProgress());
        self::assertSame(SagaState::ProcessingRequested, $this->sagaGravada?->state());
        self::assertSame(self::EVENT, $this->sagaGravada?->correlationId);
        self::assertSame(['ProcessVideo:processing-commands'], $this->tiposDespachados());
        $env = $this->dispatched[0]->envelope;
        self::assertSame(['bucket' => 'fiapx-local-videos', 'video_key' => 'uploads/'.self::VIDEO.'.mp4', 'fps' => 1, 'output_prefix' => 'results/'.self::VIDEO.'/'], $env['payload']);
        self::assertSame(self::EVENT, $env['correlation_id']);
    }

    public function test_upload_invalido_falha_notifica_e_apaga_o_arquivo(): void
    {
        $video = $this->video();
        $this->videos->expects('findForUpdate')->andReturn($video);
        $this->sagas->expects('findByVideo')->andReturnNull();
        $this->storage->expects('delete');

        self::assertSame(SagaOutcome::Rejected, $this->start(new StoredObject(600 * 1024 * 1024, 'video/mp4')));
        self::assertSame(VideoStatus::Failed, $video->status());
        self::assertSame(FailureReason::InvalidUpload, $video->failure());
        self::assertSame(SagaState::Compensated, $this->sagaGravada?->state());
        self::assertSame(['SendNotification:VIDEO_FAILED'], $this->tiposDespachados());
        self::assertSame('INVALID_UPLOAD', $this->dispatched[0]->envelope['payload']['reason']);
        self::assertSame(0, $this->quota->inProgress());
    }

    public function test_upload_sem_objeto_ou_mime_errado_e_invalido(): void
    {
        foreach ([null, new StoredObject(10, 'image/png')] as $head) {
            $this->setUp();
            $video = $this->video();
            $this->videos->expects('findForUpdate')->andReturn($video);
            $this->sagas->expects('findByVideo')->andReturnNull();
            $this->storage->expects('delete');
            self::assertSame(SagaOutcome::Rejected, $this->start($head));
        }
    }

    public function test_cota_excedida(): void
    {
        $this->quota = ProcessingQuota::restore(self::USER, 5, 5);
        $video = $this->video();
        $this->videos->expects('findForUpdate')->andReturn($video);
        $this->sagas->expects('findByVideo')->andReturnNull();
        $this->storage->expects('delete');

        self::assertSame(SagaOutcome::Rejected, $this->start(new StoredObject(1000, 'video/mp4')));
        self::assertSame(FailureReason::QuotaExceeded, $video->failure());
        self::assertSame(5, $this->quota->inProgress());
    }

    public function test_evento_ignorado_para_chave_fora_do_padrao_ou_outro_bucket(): void
    {
        $this->storage->shouldNotReceive('head');
        self::assertSame(SagaOutcome::Ignored, $this->start(null, 'uploads/qualquer.mp4'));
        $uc = new StartProcessingSagaUseCase($this->videos, $this->sagas, $this->quotas, $this->storage, $this->commands, $this->deduplicator, $this->transactions, $this->messages(), $this->ids, $this->clock, $this->settings);
        self::assertSame(SagaOutcome::Ignored, $uc->execute(new UploadedObjectEvent(self::EVENT, 'outro-bucket', 'uploads/'.self::VIDEO.'.mp4', 1)));
    }

    public function test_evento_duplicado_ou_video_ja_com_saga(): void
    {
        $this->deduplicator->expects('register')->andReturnFalse();
        self::assertSame(SagaOutcome::Duplicate, $this->start(new StoredObject(1, 'video/mp4')));

        $this->setUp();
        $video = $this->video();
        $video->markUploaded($this->now);
        $this->videos->expects('findForUpdate')->andReturn($video);
        self::assertSame(SagaOutcome::Ignored, $this->start(new StoredObject(1, 'video/mp4')));
        self::assertSame([], $this->dispatched);
    }

    public function test_resposta_started_e_processed_com_email_de_conclusao(): void
    {
        [$video, $saga] = $this->emProcessamento();
        $this->quota->reserve();
        $this->sagas->allows('find')->andReturn($saga);
        $this->videos->allows('findForUpdate')->andReturn($video);

        self::assertSame(SagaOutcome::Applied, $this->reply()->execute($this->envelope('ProcessingStarted', ['started_at' => '2026-11-16T12:00:01Z', 'worker_id' => 'w1'])));
        self::assertSame(VideoStatus::Processing, $video->status());

        $this->reply()->execute($this->envelope('VideoProcessed', ['zip_key' => 'results/'.self::VIDEO.'/frames.zip', 'frame_count' => 120, 'zip_size_bytes' => 10, 'duration_ms' => 5], '0192f1c4-2222-7000-8000-000000000002'));
        self::assertSame(VideoStatus::Completed, $video->status());
        self::assertSame(120, $video->frameCount());
        self::assertSame(0, $this->quota->inProgress());
        self::assertSame(['SendNotification:VIDEO_COMPLETED'], $this->tiposDespachados());

        $this->reply()->execute($this->envelope('NotificationSent', ['channel' => 'EMAIL'], '0192f1c4-2222-7000-8000-000000000003'));
        self::assertSame(SagaState::Notified, $saga->state());
    }

    public function test_conclusao_sem_email_marca_notified(): void
    {
        $this->settings = new SagaSettings(bucket: 'fiapx-local-videos', notifyOnCompleted: false);
        [$video, $saga] = $this->emProcessamento();
        $this->sagas->allows('find')->andReturn($saga);
        $this->videos->allows('findForUpdate')->andReturn($video);
        $this->reply()->execute($this->envelope('VideoProcessed', ['zip_key' => 'results/'.self::VIDEO.'/frames.zip', 'frame_count' => 1]));
        self::assertSame(SagaState::Notified, $saga->state());
        self::assertSame([], $this->dispatched);
    }

    public function test_falha_do_ffmpeg_compensa_e_notifica(): void
    {
        [$video, $saga] = $this->emProcessamento();
        $this->quota->reserve();
        $this->sagas->allows('find')->andReturn($saga);
        $this->videos->allows('findForUpdate')->andReturn($video);

        $this->reply()->execute($this->envelope('VideoProcessingFailed', ['error_code' => 'FFMPEG_ERROR', 'error_message' => 'x', 'attempt' => 3]));
        self::assertSame(SagaState::Compensating, $saga->state());
        self::assertSame(VideoStatus::Queued, $video->status(), 'o vídeo mantém o status até a compensação terminar');
        self::assertSame(['CleanupArtifacts:processing-commands'], $this->tiposDespachados());

        $this->reply()->execute($this->envelope('ArtifactsCleaned', ['deleted_objects' => 3], '0192f1c4-2222-7000-8000-000000000002'));
        self::assertSame(SagaState::Compensated, $saga->state());
        self::assertSame(VideoStatus::Failed, $video->status());
        self::assertSame(FailureReason::FfmpegError, $video->failure());
        self::assertSame(0, $this->quota->inProgress());
        self::assertSame('SendNotification:VIDEO_FAILED', $this->tiposDespachados()[1]);

        $this->reply()->execute($this->envelope('NotificationFailed', ['channel' => 'EMAIL', 'error' => 'SES'], '0192f1c4-2222-7000-8000-000000000003'));
        self::assertSame(SagaState::Compensated, $saga->state());
    }

    public function test_codigo_de_erro_desconhecido_vira_ffmpeg_error(): void
    {
        [$video, $saga] = $this->emProcessamento();
        $this->sagas->allows('find')->andReturn($saga);
        $this->videos->allows('findForUpdate')->andReturn($video);
        $this->reply()->execute($this->envelope('VideoProcessingFailed', ['error_code' => 'NOVO_CODIGO']));
        self::assertSame(FailureReason::FfmpegError, $saga->failure());
    }

    public function test_duplicata_e_no_op(): void
    {
        $this->deduplicator->expects('register')->andReturnFalse();
        $this->sagas->shouldNotReceive('find');
        self::assertSame(SagaOutcome::Duplicate, $this->reply()->execute($this->envelope('VideoProcessed', [])));
    }

    public function test_saga_desconhecida_ou_de_outro_video_e_ignorada(): void
    {
        $this->sagas->expects('find')->andReturnNull();
        self::assertSame(SagaOutcome::Ignored, $this->reply()->execute($this->envelope('ProcessingStarted', [])));
    }

    public function test_video_inexistente_e_ignorado(): void
    {
        [, $saga] = $this->emProcessamento();
        $this->sagas->expects('find')->andReturn($saga);
        $this->videos->expects('findForUpdate')->andReturnNull();
        self::assertSame(SagaOutcome::Ignored, $this->reply()->execute($this->envelope('ProcessingStarted', [])));
    }

    public function test_resposta_fora_de_ordem_lanca_para_o_consumidor_descartar(): void
    {
        [$video, $saga] = $this->emProcessamento();
        $saga->processingFailed(FailureReason::FfmpegError, $this->now);
        $this->sagas->allows('find')->andReturn($saga);
        $this->videos->allows('findForUpdate')->andReturn($video);
        $this->sagas->shouldNotReceive('save');
        $this->expectException(InvalidSagaTransition::class);
        $this->reply()->execute($this->envelope('VideoProcessed', ['zip_key' => 'results/'.self::VIDEO.'/frames.zip', 'frame_count' => 1]));
    }

    public function test_zip_key_de_outro_video_e_recusado(): void
    {
        [$video, $saga] = $this->emProcessamento();
        $this->sagas->allows('find')->andReturn($saga);
        $this->videos->allows('findForUpdate')->andReturn($video);
        $this->expectException(InvalidSagaTransition::class);
        $this->reply()->execute($this->envelope('VideoProcessed', ['zip_key' => 'results/outro/frames.zip', 'frame_count' => 1]));
    }

    public function test_envelope_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IncomingReply::fromEnvelope(['type' => 'Outro']);
    }

    public function test_envelope_sem_payload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IncomingReply::fromEnvelope(['type' => 'ProcessingStarted', 'message_id' => self::SAGA]);
    }

    public function test_timeouts(): void
    {
        [, $saga] = $this->emProcessamento();
        $uc = new CheckSagaTimeoutsUseCase($this->sagas, $this->commands, $this->transactions, $this->messages(), $this->clock);
        $this->sagas->expects('idsWithExpiredDeadline')->andReturn([self::SAGA]);
        self::assertSame([self::SAGA], $uc->expiredSagaIds());

        $this->sagas->allows('find')->andReturn($saga, $saga, null);
        self::assertFalse($uc->timeOut(self::SAGA), 'prazo ainda não venceu');
        $this->clock->at = $this->now->modify('+31 minutes');
        self::assertTrue($uc->timeOut(self::SAGA));
        self::assertSame(SagaState::Compensating, $saga->state());
        self::assertSame(FailureReason::ProcessingTimeout, $saga->failure());
        self::assertSame(['CleanupArtifacts:processing-commands'], $this->tiposDespachados());
        self::assertSame(self::EVENT, $this->dispatched[0]->envelope['correlation_id']);
        self::assertFalse($uc->timeOut(self::SAGA), 'saga sumiu');
    }

    public function test_prune(): void
    {
        $outbox = Mockery::mock(OutboxHousekeeping::class);
        $outbox->expects('prunePublishedBefore')->withArgs(fn ($l) => $l == $this->now->modify('-7 days'))->andReturn(3);
        $this->deduplicator->expects('pruneOlderThan')->andReturn(5);
        self::assertSame(['outbox' => 3, 'processed_messages' => 5], (new PruneMessagingUseCase($outbox, $this->deduplicator, $this->clock, $this->settings))->execute());
    }
}
