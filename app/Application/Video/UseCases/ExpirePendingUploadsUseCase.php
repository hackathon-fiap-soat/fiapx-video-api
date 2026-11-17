<?php

declare(strict_types=1);

namespace App\Application\Video\UseCases;

use App\Application\Shared\Ports\Telemetry;
use App\Application\Shared\Ports\TransactionManager;
use App\Application\Shared\SagaSettings;
use App\Application\Shared\Telemetry\VideoSagaTransition;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\VideoStatus;

/**
 * videos:expire-pending: PENDING_UPLOAD há mais de 1 hora vira EXPIRED. Cada vídeo é relido com lock, porque
 * o evento do upload pode chegar ao mesmo tempo (a saga também bloqueia a linha do vídeo). Depois do commit
 * de cada vídeo, registra o VideoSagaTransition da expiração (step ExpirePendingUpload, sem saga).
 */
final readonly class ExpirePendingUploadsUseCase
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private TransactionManager $transactions,
        private Clock $clock,
        private SagaSettings $settings,
        private Telemetry $telemetry,
    ) {}

    public function execute(int $max = 500): int
    {
        $limit = $this->clock->now()->modify("-{$this->settings->pendingUploadTtlSeconds} seconds");
        $expired = 0;
        foreach ($this->videos->pendingUploadCreatedBefore($limit, $max) as $candidate) {
            $video = $this->transactions->run(function () use ($candidate): ?Video {
                $video = $this->videos->findForUpdate($candidate->id);
                if ($video === null || $video->status() !== VideoStatus::PendingUpload) {
                    return null;
                }
                $video->expire($this->clock->now());
                $this->videos->save($video);

                return $video;
            });
            if ($video !== null) {
                $expired++;
                $this->telemetry->recordTransition(VideoSagaTransition::videoExpired($video));
            }
        }

        return $expired;
    }
}
