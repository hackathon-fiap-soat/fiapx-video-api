<?php

declare(strict_types=1);

namespace App\Application\Video\UseCases;

use App\Application\Shared\Ports\TransactionManager;
use App\Application\Shared\SagaSettings;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\VideoStatus;

/**
 * videos:expire-pending: PENDING_UPLOAD há mais de 1 hora vira EXPIRED. Cada vídeo é relido com lock, porque
 * o evento do upload pode chegar ao mesmo tempo (a saga também bloqueia a linha do vídeo).
 */
final readonly class ExpirePendingUploadsUseCase
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private TransactionManager $transactions,
        private Clock $clock,
        private SagaSettings $settings,
    ) {}

    public function execute(int $max = 500): int
    {
        $limit = $this->clock->now()->modify("-{$this->settings->pendingUploadTtlSeconds} seconds");
        $expired = 0;
        foreach ($this->videos->pendingUploadCreatedBefore($limit, $max) as $candidate) {
            $expired += $this->transactions->run(function () use ($candidate): int {
                $video = $this->videos->findForUpdate($candidate->id);
                if ($video === null || $video->status() !== VideoStatus::PendingUpload) {
                    return 0;
                }
                $video->expire($this->clock->now());
                $this->videos->save($video);

                return 1;
            });
        }

        return $expired;
    }
}
