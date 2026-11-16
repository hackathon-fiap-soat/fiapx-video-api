<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Shared\SagaSettings;
use App\Domain\Quota\Entities\ProcessingQuota;
use App\Domain\Quota\Interfaces\ProcessingQuotaRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\ProcessingQuotaModel;

final readonly class EloquentProcessingQuotaRepository implements ProcessingQuotaRepositoryInterface
{
    public function __construct(private SagaSettings $settings) {}

    /** Cria a linha se não existir e a bloqueia (SELECT ... FOR UPDATE) até o fim da transação. */
    public function lockForUser(string $userId): ProcessingQuota
    {
        ProcessingQuotaModel::query()->insertOrIgnore([
            'user_id' => $userId, 'in_progress' => 0, 'max_parallel' => $this->settings->maxParallelPerUser,
        ]);
        $m = ProcessingQuotaModel::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

        return ProcessingQuota::restore($m->user_id, (int) $m->in_progress, (int) $m->max_parallel);
    }

    public function save(ProcessingQuota $quota): void
    {
        ProcessingQuotaModel::query()->whereKey($quota->userId)->update(['in_progress' => $quota->inProgress()]);
    }

    public function findForUser(string $userId): ?ProcessingQuota
    {
        $m = ProcessingQuotaModel::query()->find($userId);

        return $m === null ? null : ProcessingQuota::restore($m->user_id, (int) $m->in_progress, (int) $m->max_parallel);
    }
}
