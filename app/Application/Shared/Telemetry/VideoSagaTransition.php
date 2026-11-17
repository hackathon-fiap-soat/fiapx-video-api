<?php

declare(strict_types=1);

namespace App\Application\Shared\Telemetry;

use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\ValueObjects\SagaState;
use App\Domain\Saga\ValueObjects\SagaStep;
use App\Domain\Video\Entities\Video;

/**
 * Evento customizado VideoSagaTransition (fiapx-docs/07-observabilidade/observabilidade.md). As transições da
 * saga saem do histórico dela (cada entrada com estado diferente da anterior é uma transição); as do vídeo
 * fora da saga (criação e expiração) vêm com saga_id, from_state e to_state vazios.
 */
final readonly class VideoSagaTransition
{
    public const EVENT_TYPE = 'VideoSagaTransition';

    public const NO_STATE = 'NONE';

    public const TRIGGER_UPLOAD_REQUEST = 'POST /api/videos';

    public const TRIGGER_EXPIRATION = 'videos:expire-pending';

    public const STEP_REQUEST_UPLOAD = 'RequestVideoUpload';

    public const STEP_EXPIRE_UPLOAD = 'ExpirePendingUpload';

    public function __construct(
        public string $videoId,
        public string $sagaId,
        public string $correlationId,
        public string $userId,
        public string $fromState,
        public string $toState,
        public string $step,
        public int $durationMs,
        public string $videoStatus,
        public string $trigger,
        public ?string $failureReason = null,
    ) {}

    /**
     * Transições registradas no histórico da saga a partir da posição $fromIndex (o tamanho do histórico antes
     * de aplicar a mensagem; 0 para uma saga nova, que começa em NONE → STARTED).
     *
     * O passo é o que estava em execução na transição: ao entrar em COMPENSATING, o passo de compensação
     * iniciado; em COMPLETED, CompleteVideo (passo local do VideoProcessed).
     *
     * @return list<self>
     */
    public static function fromSaga(ProcessingSaga $saga, Video $video, int $fromIndex, string $trigger): array
    {
        $history = $saga->history();
        $transitions = [];
        for ($i = max(0, $fromIndex); $i < count($history); $i++) {
            $entry = $history[$i];
            $previous = $history[$i - 1] ?? null;
            if ($previous !== null && $previous['state'] === $entry['state']) {
                continue;
            }
            $failure = in_array($entry['state'], [SagaState::Compensating->value, SagaState::Compensated->value], true)
                ? $saga->failure()?->value
                : null;

            $transitions[] = new self(
                $video->id->value,
                $saga->id,
                $saga->correlationId,
                $video->owner->userId,
                $previous['state'] ?? self::NO_STATE,
                $entry['state'],
                self::step($entry, $previous),
                $previous === null ? 0 : self::millisBetween(self::enteredAt($history, $i - 1), $entry['at']),
                $video->status()->value,
                $trigger,
                $failure,
            );
        }

        return $transitions;
    }

    /** Vídeo criado em PENDING_UPLOAD pelo POST /api/videos (sem saga). */
    public static function videoCreated(Video $video, string $correlationId): self
    {
        return new self(
            $video->id->value, '', $correlationId, $video->owner->userId, '', '', self::STEP_REQUEST_UPLOAD, 0,
            $video->status()->value, self::TRIGGER_UPLOAD_REQUEST,
        );
    }

    /** PENDING_UPLOAD → EXPIRED (sem saga). duration_ms = tempo em PENDING_UPLOAD. */
    public static function videoExpired(Video $video): self
    {
        return new self(
            $video->id->value, '', '', $video->owner->userId, '', '', self::STEP_EXPIRE_UPLOAD,
            self::millisBetween($video->createdAt, $video->updatedAt()), $video->status()->value, self::TRIGGER_EXPIRATION,
        );
    }

    /**
     * Atributos do evento; failure_reason só nas falhas.
     *
     * @return array<string, string|int>
     */
    public function attributes(): array
    {
        $attributes = [
            'video_id' => $this->videoId,
            'saga_id' => $this->sagaId,
            'correlation_id' => $this->correlationId,
            'user_id' => $this->userId,
            'from_state' => $this->fromState,
            'to_state' => $this->toState,
            'step' => $this->step,
            'duration_ms' => $this->durationMs,
            'video_status' => $this->videoStatus,
            'trigger' => $this->trigger,
        ];
        if ($this->failureReason !== null) {
            $attributes['failure_reason'] = $this->failureReason;
        }

        return $attributes;
    }

    /**
     * @param  array{step: string, state: string, at: string, detail: ?string}  $entry
     * @param  array{step: string, state: string, at: string, detail: ?string}|null  $previous
     */
    private static function step(array $entry, ?array $previous): string
    {
        return match (true) {
            $previous === null, $entry['state'] === SagaState::Compensating->value => $entry['step'],
            $entry['state'] === SagaState::Completed->value => SagaStep::CompleteVideo->value,
            default => $previous['step'],
        };
    }

    /**
     * Momento em que a saga entrou no estado da entrada $index (primeira entrada da sequência com esse estado).
     *
     * @param  list<array{step: string, state: string, at: string, detail: ?string}>  $history
     */
    private static function enteredAt(array $history, int $index): string
    {
        while ($index > 0 && $history[$index - 1]['state'] === $history[$index]['state']) {
            $index--;
        }

        return $history[$index]['at'];
    }

    private static function millisBetween(string|\DateTimeImmutable $start, string|\DateTimeImmutable $end): int
    {
        $start = is_string($start) ? new \DateTimeImmutable($start) : $start;
        $end = is_string($end) ? new \DateTimeImmutable($end) : $end;

        return max(0, (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000));
    }
}
