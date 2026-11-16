<?php

declare(strict_types=1);

namespace App\Application\Shared\Ports;

interface OutboxHousekeeping
{
    /** Apaga mensagens já publicadas antes do limite; devolve quantas. */
    public function prunePublishedBefore(\DateTimeImmutable $limit): int;
}
