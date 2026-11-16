<?php

declare(strict_types=1);

namespace App\Domain\Saga\Entities;

use App\Domain\Saga\Exceptions\InvalidSagaTransition;
use App\Domain\Saga\ValueObjects\Deadline;
use App\Domain\Saga\ValueObjects\SagaState;
use App\Domain\Saga\ValueObjects\SagaStep;
use App\Domain\Shared\ValueObjects\Uuid;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\VideoId;

/**
 * Orquestrador da SAGA de processamento de um vídeo (ADR-008). Classe da camada de domínio, sem dependência
 * do Laravel (ADR-003): decide o próximo estado e o passo atual; quem executa os efeitos (cota, outbox, S3) são
 * os casos de uso, na mesma transação, usando o Laravel pela camada de infraestrutura.
 *
 * Sucesso:  STARTED → UPLOAD_VALIDATED → QUOTA_RESERVED → PROCESSING_REQUESTED → PROCESSING → COMPLETED → NOTIFIED
 * Falha:    ... → COMPENSATING → COMPENSATED (vídeo FAILED) → NOTIFIED
 *
 * Transição fora de ordem lança InvalidSagaTransition.
 */
final class ProcessingSaga
{
    /**
     * @param  list<array{step: string, state: string, at: string, detail: ?string}>  $history
     */
    private function __construct(
        public readonly string $id,
        public readonly VideoId $videoId,
        public readonly string $correlationId,
        private SagaState $state,
        private SagaStep $currentStep,
        private ?Deadline $deadline,
        private int $attempts,
        private array $history,
        private int $version,
    ) {}

    /** Saga iniciada pelo evento do S3. correlationId = id da mensagem do evento (copiado em todos os comandos). */
    public static function start(string $id, VideoId $videoId, string $correlationId, \DateTimeImmutable $now): self
    {
        $saga = new self(
            Uuid::assert($id, 'Id da saga'), $videoId, Uuid::assert($correlationId, 'correlation_id'),
            SagaState::Started, SagaStep::ValidateUpload, null, 0, [], 0,
        );
        $saga->record($now);

        return $saga;
    }

    /**
     * @param  list<array{step: string, state: string, at: string, detail: ?string}>  $history
     */
    public static function restore(
        string $id, VideoId $videoId, string $correlationId, SagaState $state, SagaStep $currentStep,
        ?Deadline $deadline, int $attempts, array $history, int $version,
    ): self {
        return new self($id, $videoId, $correlationId, $state, $currentStep, $deadline, $attempts, $history, $version);
    }

    // --- Passos 1 e 2 (locais, mesma transação do evento do S3) -----------------------------------------

    public function uploadValidated(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::Started], 'ValidateUpload ok');
        $this->moveTo(SagaState::UploadValidated, SagaStep::ReserveQuota, $now);
    }

    /** Upload inválido: compensação DeleteUploadedObject. */
    public function rejectUpload(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::Started], 'upload inválido');
        $this->moveTo(SagaState::Compensating, SagaStep::DeleteUploadedObject, $now, FailureReason::InvalidUpload->value);
    }

    public function quotaReserved(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::UploadValidated], 'ReserveQuota ok');
        $this->moveTo(SagaState::QuotaReserved, SagaStep::ProcessVideo, $now);
    }

    /** Cota excedida: compensação DeleteUploadedObject (nada foi reservado). */
    public function rejectQuota(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::UploadValidated], 'cota excedida');
        $this->moveTo(SagaState::Compensating, SagaStep::DeleteUploadedObject, $now, FailureReason::QuotaExceeded->value);
    }

    /** ProcessVideo gravado na outbox: o prazo começa a contar. */
    public function processingRequested(\DateTimeImmutable $now, int $timeoutSeconds = Deadline::DEFAULT_SECONDS): void
    {
        $this->expect([SagaState::QuotaReserved], 'ProcessVideo na outbox');
        $this->deadline = Deadline::from($now, $timeoutSeconds);
        $this->attempts++;
        $this->moveTo(SagaState::ProcessingRequested, SagaStep::ProcessVideo, $now);
    }

    // --- Respostas do processador --------------------------------------------------------------------

    public function processingStarted(\DateTimeImmutable $now, int $timeoutSeconds = Deadline::DEFAULT_SECONDS): void
    {
        $this->expect([SagaState::ProcessingRequested], 'ProcessingStarted');
        $this->deadline = Deadline::from($now, $timeoutSeconds);
        $this->moveTo(SagaState::Processing, SagaStep::ProcessVideo, $now);
    }

    /** VideoProcessed: ponto sem retorno. Aceito também sem ProcessingStarted antes. */
    public function processed(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::ProcessingRequested, SagaState::Processing], 'VideoProcessed');
        $this->deadline = null;
        $this->moveTo(SagaState::Completed, SagaStep::NotifyUser, $now);
    }

    /** VideoProcessingFailed: compensa com CleanupArtifacts e depois ReleaseQuota. */
    public function processingFailed(FailureReason $reason, \DateTimeImmutable $now): void
    {
        $this->expect([SagaState::ProcessingRequested, SagaState::Processing], 'VideoProcessingFailed');
        $this->deadline = null;
        $this->moveTo(SagaState::Compensating, SagaStep::CleanupArtifacts, $now, $reason->value);
    }

    /** Prazo vencido sem resposta final: mesma compensação, com PROCESSING_TIMEOUT. */
    public function timeOut(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::ProcessingRequested, SagaState::Processing], 'timeout');
        if ($this->deadline === null || ! $this->deadline->isExpired($now)) {
            throw new InvalidSagaTransition('O prazo da saga ainda não venceu.');
        }
        $this->deadline = null;
        $this->moveTo(SagaState::Compensating, SagaStep::CleanupArtifacts, $now, FailureReason::ProcessingTimeout->value);
    }

    /** ArtifactsCleaned: falta só liberar a cota (feito pelo caso de uso na mesma transação). */
    public function artifactsCleaned(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::Compensating], 'ArtifactsCleaned');
        if ($this->currentStep !== SagaStep::CleanupArtifacts) {
            throw new InvalidSagaTransition('ArtifactsCleaned fora do passo CleanupArtifacts.');
        }
        $this->record($now, SagaStep::ReleaseQuota);
        $this->moveTo(SagaState::Compensated, SagaStep::NotifyUser, $now, $this->failure()?->value);
    }

    /** Compensação local concluída (DeleteUploadedObject é feito depois do commit, idempotente). */
    public function compensatedLocally(\DateTimeImmutable $now): void
    {
        $this->expect([SagaState::Compensating], 'compensação local');
        if ($this->currentStep !== SagaStep::DeleteUploadedObject) {
            throw new InvalidSagaTransition('Compensação local fora do passo DeleteUploadedObject.');
        }
        $this->moveTo(SagaState::Compensated, SagaStep::NotifyUser, $now, $this->failure()?->value);
    }

    // --- Notificação ----------------------------------------------------------------------------------

    /** NotificationSent, ou conclusão sem e-mail de VIDEO_COMPLETED. */
    public function notified(\DateTimeImmutable $now, ?string $detail = null): void
    {
        $this->expect([SagaState::Completed, SagaState::Compensated], 'NotificationSent');
        $this->moveTo(SagaState::Notified, SagaStep::Done, $now, $detail);
    }

    /** NotificationFailed: registra e continua no estado atual; o alarme da DLQ avisa o time. */
    public function notificationFailed(?string $error, \DateTimeImmutable $now): void
    {
        $this->expect([SagaState::Completed, SagaState::Compensated], 'NotificationFailed');
        $this->record($now, $this->currentStep, 'NotificationFailed: '.($error ?? 'sem detalhe'));
    }

    // --- Consultas ------------------------------------------------------------------------------------

    public function state(): SagaState
    {
        return $this->state;
    }

    public function currentStep(): SagaStep
    {
        return $this->currentStep;
    }

    public function deadline(): ?Deadline
    {
        return $this->deadline;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /** @return list<array{step: string, state: string, at: string, detail: ?string}> */
    public function history(): array
    {
        return $this->history;
    }

    /** Versão lida do banco (lock otimista). */
    public function version(): int
    {
        return $this->version;
    }

    /** Motivo da falha guardado ao entrar em COMPENSATING. */
    public function failure(): ?FailureReason
    {
        foreach (array_reverse($this->history) as $entry) {
            if ($entry['state'] === SagaState::Compensating->value && $entry['detail'] !== null) {
                return FailureReason::tryFrom($entry['detail']);
            }
        }

        return null;
    }

    /** A cota foi reservada nesta saga (para saber se ReleaseQuota é necessário). */
    public function reservedQuota(): bool
    {
        foreach ($this->history as $entry) {
            if ($entry['state'] === SagaState::QuotaReserved->value) {
                return true;
            }
        }

        return false;
    }

    /** @param list<SagaState> $allowed */
    private function expect(array $allowed, string $event): void
    {
        if (! in_array($this->state, $allowed, true)) {
            throw new InvalidSagaTransition("{$event} não é aceito com a saga em {$this->state->value}.");
        }
    }

    private function moveTo(SagaState $state, SagaStep $step, \DateTimeImmutable $now, ?string $detail = null): void
    {
        $this->state = $state;
        $this->currentStep = $step;
        $this->record($now, $step, $detail);
    }

    private function record(\DateTimeImmutable $now, ?SagaStep $step = null, ?string $detail = null): void
    {
        $this->history[] = [
            'step' => ($step ?? $this->currentStep)->value,
            'state' => $this->state->value,
            'at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'detail' => $detail,
        ];
    }
}
