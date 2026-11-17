<?php

declare(strict_types=1);

namespace App\Application\Saga\UseCases;

use App\Application\Messaging\MessageFactory;
use App\Application\Saga\DTOs\SagaOutcome;
use App\Application\Saga\DTOs\UploadedObjectEvent;
use App\Application\Shared\Ports\CommandBus;
use App\Application\Shared\Ports\MessageDeduplicator;
use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Shared\Ports\Telemetry;
use App\Application\Shared\Ports\TransactionManager;
use App\Application\Shared\SagaSettings;
use App\Application\Shared\Telemetry\VideoSagaTransition;
use App\Application\Video\DTOs\StoredObject;
use App\Domain\Quota\Interfaces\ProcessingQuotaRepositoryInterface;
use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Shared\Interfaces\IdGenerator;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Domain\Video\ValueObjects\VideoFormat;
use App\Domain\Video\ValueObjects\VideoStatus;

/**
 * Início da SAGA pelo evento do S3 (spec §6.1, passos 1 e 2). O HeadObject é feito antes; depois, numa só
 * transação: saga, ValidateUpload, ReserveQuota, ProcessVideo na outbox, status do vídeo e
 * processed_messages. Na falha local, a compensação DeleteUploadedObject apaga o arquivo depois do commit.
 * Depois do commit, cada transição da saga vira um evento VideoSagaTransition (trigger S3ObjectCreated).
 */
final readonly class StartProcessingSagaUseCase
{
    /** Tipo da mensagem que inicia a saga (registro em processed_messages e trigger do VideoSagaTransition). */
    private const TRIGGER = 'S3ObjectCreated';

    public function __construct(
        private VideoRepositoryInterface $videos,
        private ProcessingSagaRepositoryInterface $sagas,
        private ProcessingQuotaRepositoryInterface $quotas,
        private ObjectStorage $storage,
        private CommandBus $commands,
        private MessageDeduplicator $deduplicator,
        private TransactionManager $transactions,
        private MessageFactory $messages,
        private IdGenerator $ids,
        private Clock $clock,
        private SagaSettings $settings,
        private Telemetry $telemetry,
    ) {}

    public function execute(UploadedObjectEvent $event): SagaOutcome
    {
        $videoId = StorageKey::videoIdFromUploadKey($event->key);
        if ($videoId === null || $event->bucket !== $this->settings->bucket) {
            return SagaOutcome::Ignored;
        }
        $key = StorageKey::fromString($event->key);
        $head = $this->storage->head($key);

        $transitions = [];
        $outcome = $this->transactions->run(function () use ($event, $videoId, $key, $head, &$transitions): SagaOutcome {
            if (! $this->deduplicator->register($event->eventId, self::TRIGGER)) {
                return SagaOutcome::Duplicate;
            }
            $video = $this->videos->findForUpdate($videoId);
            // Vídeo desconhecido, já com saga (evento repetido com outro id) ou expirado: nada a fazer.
            if ($video === null || $video->status() !== VideoStatus::PendingUpload || $this->sagas->findByVideo($videoId) !== null) {
                return SagaOutcome::Ignored;
            }

            $now = $this->clock->now();
            $video->markUploaded($now);
            $saga = ProcessingSaga::start($this->ids->next(), $videoId, $event->eventId, $now);

            if (! $this->uploadIsValid($key, $head)) {
                $saga->rejectUpload($now);

                return $this->failLocally($saga, $video, FailureReason::InvalidUpload, $now, $transitions);
            }
            $saga->uploadValidated($now);

            $quota = $this->quotas->lockForUser($video->owner->userId);
            if (! $quota->canReserve()) {
                $saga->rejectQuota($now);

                return $this->failLocally($saga, $video, FailureReason::QuotaExceeded, $now, $transitions);
            }
            $quota->reserve();
            $this->quotas->save($quota);
            $saga->quotaReserved($now);

            $video->markQueued($now);
            $this->commands->dispatch($this->messages->processVideo($saga, $video));
            $saga->processingRequested($now, $this->settings->processingTimeoutSeconds);

            $this->sagas->add($saga);
            $this->videos->save($video);
            $transitions = VideoSagaTransition::fromSaga($saga, $video, 0, self::TRIGGER);

            return SagaOutcome::Started;
        });

        foreach ($transitions as $transition) {
            $this->telemetry->recordTransition($transition);
        }

        if ($outcome === SagaOutcome::Rejected) {
            $this->storage->delete($key);
        }

        return $outcome;
    }

    /** ValidateUpload: o objeto existe, tem até 500 MB, extensão aceita e MIME de vídeo. */
    private function uploadIsValid(StorageKey $key, ?StoredObject $head): bool
    {
        return $head !== null
            && FileSize::fits($head->sizeBytes)
            && $key->format() !== null
            && VideoFormat::isAcceptedMimeType($head->contentType);
    }

    /**
     * Falha nos passos locais: vídeo FAILED, saga COMPENSATED e VIDEO_FAILED na outbox.
     *
     * @param  list<VideoSagaTransition>  $transitions  preenchida com as transições, emitidas depois do commit
     */
    private function failLocally(ProcessingSaga $saga, Video $video, FailureReason $reason, \DateTimeImmutable $now, array &$transitions): SagaOutcome
    {
        $video->fail($reason, $now);
        $saga->compensatedLocally($now);
        $this->commands->dispatch($this->messages->videoFailed($saga, $video));
        $this->sagas->add($saga);
        $this->videos->save($video);
        $transitions = VideoSagaTransition::fromSaga($saga, $video, 0, self::TRIGGER);

        return SagaOutcome::Rejected;
    }
}
