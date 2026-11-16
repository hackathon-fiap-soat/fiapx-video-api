<?php

declare(strict_types=1);

namespace App\Application\Saga\UseCases;

use App\Application\Messaging\IncomingReply;
use App\Application\Messaging\MessageFactory;
use App\Application\Saga\DTOs\SagaOutcome;
use App\Application\Shared\Ports\CommandBus;
use App\Application\Shared\Ports\MessageDeduplicator;
use App\Application\Shared\Ports\TransactionManager;
use App\Application\Shared\SagaSettings;
use App\Domain\Quota\Interfaces\ProcessingQuotaRepositoryInterface;
use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\Exceptions\InvalidSagaTransition;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Domain\Video\ValueObjects\VideoId;

/**
 * Aplica as respostas do processor e da notification à saga (spec §6). Tudo numa transação: o registro em
 * processed_messages (mensagem repetida vira no-op), a saga com lock otimista, o vídeo, a cota e os
 * comandos na outbox.
 *
 * @throws InvalidSagaTransition resposta fora de ordem: o consumidor descarta com warning
 */
final readonly class HandleSagaReplyUseCase
{
    public function __construct(
        private ProcessingSagaRepositoryInterface $sagas,
        private VideoRepositoryInterface $videos,
        private ProcessingQuotaRepositoryInterface $quotas,
        private CommandBus $commands,
        private MessageDeduplicator $deduplicator,
        private TransactionManager $transactions,
        private MessageFactory $messages,
        private Clock $clock,
        private SagaSettings $settings,
    ) {}

    public function execute(IncomingReply $reply): SagaOutcome
    {
        return $this->transactions->run(function () use ($reply): SagaOutcome {
            if (! $this->deduplicator->register($reply->messageId, $reply->type)) {
                return SagaOutcome::Duplicate;
            }
            $saga = $this->sagas->find($reply->sagaId);
            if ($saga === null || $saga->videoId->value !== $reply->videoId) {
                return SagaOutcome::Ignored;
            }
            $video = $this->videos->findForUpdate(new VideoId($reply->videoId));
            if ($video === null) {
                return SagaOutcome::Ignored;
            }

            $now = $this->clock->now();
            match ($reply->type) {
                'ProcessingStarted' => $this->started($saga, $video, $now),
                'VideoProcessed' => $this->processed($saga, $video, $reply, $now),
                'VideoProcessingFailed' => $this->failed($saga, $reply, $now),
                'ArtifactsCleaned' => $this->cleaned($saga, $video, $now),
                'NotificationSent' => $saga->notified($now, 'NotificationSent'),
                'NotificationFailed' => $saga->notificationFailed(isset($reply->payload['error']) ? (string) $reply->payload['error'] : null, $now),
                default => throw new \InvalidArgumentException("Tipo de resposta desconhecido: {$reply->type}."),
            };

            $this->sagas->save($saga);
            $this->videos->save($video);

            return SagaOutcome::Applied;
        });
    }

    private function started(ProcessingSaga $saga, Video $video, \DateTimeImmutable $now): void
    {
        $saga->processingStarted($now, $this->settings->processingTimeoutSeconds);
        $video->markProcessing($now);
    }

    /** Passo 4 (CompleteVideo): vídeo COMPLETED, cota liberada e, se configurado, e-mail de conclusão. */
    private function processed(ProcessingSaga $saga, Video $video, IncomingReply $reply, \DateTimeImmutable $now): void
    {
        $saga->processed($now);
        $expected = StorageKey::framesZip($video->id)->value;
        $zipKey = (string) ($reply->payload['zip_key'] ?? $expected);
        if ($zipKey !== $expected) {
            throw new InvalidSagaTransition("zip_key inesperado: {$zipKey}.");
        }
        $video->complete(StorageKey::fromString($zipKey), (int) ($reply->payload['frame_count'] ?? 0), $now);
        $this->releaseQuota($video);

        if ($this->settings->notifyOnCompleted) {
            $this->commands->dispatch($this->messages->videoCompleted($saga, $video));
        } else {
            $saga->notified($now, 'sem e-mail de conclusão');
        }
    }

    /** VideoProcessingFailed: compensação começa por CleanupArtifacts. */
    private function failed(ProcessingSaga $saga, IncomingReply $reply, \DateTimeImmutable $now): void
    {
        $reason = FailureReason::tryFrom((string) ($reply->payload['error_code'] ?? '')) ?? FailureReason::FfmpegError;
        $saga->processingFailed($reason, $now);
        $this->commands->dispatch($this->messages->cleanupArtifacts($saga));
    }

    /** ArtifactsCleaned: ReleaseQuota, vídeo FAILED e VIDEO_FAILED na outbox. */
    private function cleaned(ProcessingSaga $saga, Video $video, \DateTimeImmutable $now): void
    {
        $saga->artifactsCleaned($now);
        if ($saga->reservedQuota()) {
            $this->releaseQuota($video);
        }
        $video->fail($saga->failure() ?? FailureReason::FfmpegError, $now);
        $this->commands->dispatch($this->messages->videoFailed($saga, $video));
    }

    private function releaseQuota(Video $video): void
    {
        $quota = $this->quotas->lockForUser($video->owner->userId);
        $quota->release();
        $this->quotas->save($quota);
    }
}
