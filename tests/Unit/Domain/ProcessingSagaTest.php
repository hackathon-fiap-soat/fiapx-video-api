<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\Exceptions\InvalidSagaTransition;
use App\Domain\Saga\ValueObjects\Deadline;
use App\Domain\Saga\ValueObjects\SagaState;
use App\Domain\Saga\ValueObjects\SagaStep;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\VideoId;
use PHPUnit\Framework\TestCase;

final class ProcessingSagaTest extends TestCase
{
    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        $this->t0 = new \DateTimeImmutable('2026-11-16T12:00:00Z');
    }

    private function saga(): ProcessingSaga
    {
        return ProcessingSaga::start('0192f1c4-0000-7000-8000-000000000001', new VideoId('0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f'), '0192f1c4-0000-7000-8000-0000000000aa', $this->t0);
    }

    private function emProcessamento(): ProcessingSaga
    {
        $s = $this->saga();
        $s->uploadValidated($this->t0);
        $s->quotaReserved($this->t0);
        $s->processingRequested($this->t0);

        return $s;
    }

    public function test_caminho_feliz(): void
    {
        $s = $this->emProcessamento();
        self::assertSame(SagaState::ProcessingRequested, $s->state());
        self::assertEquals($this->t0->modify('+1800 seconds'), $s->deadline()?->at);
        self::assertSame(1, $s->attempts());
        self::assertTrue($s->reservedQuota());

        $t1 = $this->t0->modify('+10 minutes');
        $s->processingStarted($t1);
        self::assertEquals($t1->modify('+1800 seconds'), $s->deadline()?->at, 'prazo renovado no ProcessingStarted');

        $s->processed($t1);
        self::assertSame(SagaState::Completed, $s->state());
        self::assertNull($s->deadline());
        $s->notified($t1);
        self::assertSame(SagaState::Notified, $s->state());
        self::assertSame(SagaStep::Done, $s->currentStep());
        self::assertSame(['STARTED', 'UPLOAD_VALIDATED', 'QUOTA_RESERVED', 'PROCESSING_REQUESTED', 'PROCESSING', 'COMPLETED', 'NOTIFIED'], array_column($s->history(), 'state'));
    }

    public function test_video_processed_sem_started_e_aceito(): void
    {
        $s = $this->emProcessamento();
        $s->processed($this->t0);
        self::assertSame(SagaState::Completed, $s->state());
    }

    public function test_falha_do_processador_compensa_com_cleanup_e_release(): void
    {
        $s = $this->emProcessamento();
        $s->processingStarted($this->t0);
        $s->processingFailed(FailureReason::FfmpegError, $this->t0);
        self::assertSame(SagaState::Compensating, $s->state());
        self::assertSame(SagaStep::CleanupArtifacts, $s->currentStep());
        self::assertSame(FailureReason::FfmpegError, $s->failure());

        $s->artifactsCleaned($this->t0);
        self::assertSame(SagaState::Compensated, $s->state());
        self::assertSame(SagaStep::NotifyUser, $s->currentStep());
        self::assertContains('ReleaseQuota', array_column($s->history(), 'step'));
        self::assertSame(FailureReason::FfmpegError, $s->failure());
        $s->notificationFailed('SES fora', $this->t0);
        self::assertSame(SagaState::Compensated, $s->state());
        $historico = $s->history();
        self::assertStringContainsString('SES fora', (string) end($historico)['detail']);
    }

    public function test_timeout_so_depois_do_prazo(): void
    {
        $s = $this->emProcessamento();
        try {
            $s->timeOut($this->t0->modify('+29 minutes'));
            self::fail('deveria recusar antes do prazo');
        } catch (InvalidSagaTransition) {
        }
        $s->timeOut($this->t0->modify('+30 minutes'));
        self::assertSame(SagaState::Compensating, $s->state());
        self::assertSame(FailureReason::ProcessingTimeout, $s->failure());
    }

    public function test_upload_invalido_compensa_localmente(): void
    {
        $s = $this->saga();
        $s->rejectUpload($this->t0);
        self::assertSame(SagaStep::DeleteUploadedObject, $s->currentStep());
        $s->compensatedLocally($this->t0);
        self::assertSame(SagaState::Compensated, $s->state());
        self::assertSame(FailureReason::InvalidUpload, $s->failure());
        self::assertFalse($s->reservedQuota());
    }

    public function test_cota_excedida_compensa_localmente(): void
    {
        $s = $this->saga();
        $s->uploadValidated($this->t0);
        $s->rejectQuota($this->t0);
        $s->compensatedLocally($this->t0);
        self::assertSame(FailureReason::QuotaExceeded, $s->failure());
    }

    public function test_resposta_fora_de_ordem_e_recusada(): void
    {
        $s = $this->emProcessamento();
        $s->processingFailed(FailureReason::FfmpegError, $this->t0);
        $this->expectException(InvalidSagaTransition::class);
        $s->processed($this->t0);
    }

    public function test_artifacts_cleaned_fora_do_passo(): void
    {
        $s = $this->saga();
        $s->rejectUpload($this->t0);
        $this->expectException(InvalidSagaTransition::class);
        $s->artifactsCleaned($this->t0);
    }

    public function test_compensacao_local_fora_do_passo(): void
    {
        $s = $this->emProcessamento();
        $s->processingFailed(FailureReason::NoFrames, $this->t0);
        $this->expectException(InvalidSagaTransition::class);
        $s->compensatedLocally($this->t0);
    }

    public function test_started_duplicado_e_recusado(): void
    {
        $s = $this->emProcessamento();
        $s->processingStarted($this->t0);
        $this->expectException(InvalidSagaTransition::class);
        $s->processingStarted($this->t0);
    }

    public function test_restore_preserva_estado(): void
    {
        $s = $this->emProcessamento();
        $r = ProcessingSaga::restore($s->id, $s->videoId, $s->correlationId, $s->state(), $s->currentStep(), $s->deadline(), $s->attempts(), $s->history(), 7);
        self::assertSame(7, $r->version());
        self::assertSame(SagaState::ProcessingRequested, $r->state());
        self::assertTrue(SagaState::Processing->awaitsProcessor());
        self::assertFalse(SagaState::Completed->awaitsProcessor());
    }

    public function test_deadline_e_ids(): void
    {
        self::assertTrue(Deadline::from($this->t0, 60)->isExpired($this->t0->modify('+60 seconds')));
        $this->expectException(\InvalidArgumentException::class);
        Deadline::from($this->t0, 0);
    }

    public function test_correlation_id_precisa_ser_uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProcessingSaga::start('0192f1c4-0000-7000-8000-000000000001', new VideoId('0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f'), 'abc', $this->t0);
    }

    public function test_timeout_sem_prazo_e_recusado(): void
    {
        $s = ProcessingSaga::restore('0192f1c4-0000-7000-8000-000000000001', new VideoId('0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f'), '0192f1c4-0000-7000-8000-0000000000aa', SagaState::Processing, SagaStep::ProcessVideo, null, 1, [], 0);
        $this->expectException(InvalidSagaTransition::class);
        $s->timeOut($this->t0);
    }
}
