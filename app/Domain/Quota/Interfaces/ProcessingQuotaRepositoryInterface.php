<?php

declare(strict_types=1);

namespace App\Domain\Quota\Interfaces;

use App\Domain\Quota\Entities\ProcessingQuota;

interface ProcessingQuotaRepositoryInterface
{
    /** Cota do usuário com a linha bloqueada até o fim da transação; cria se não existir. */
    public function lockForUser(string $userId): ProcessingQuota;

    public function save(ProcessingQuota $quota): void;

    public function findForUser(string $userId): ?ProcessingQuota;
}
