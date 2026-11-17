<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** Resultado de um lote do outbox-relay: quantas mensagens saíram e quantas falharam (voltam a ser tentadas). */
final readonly class OutboxBatchResult
{
    public function __construct(public int $published = 0, public int $failed = 0) {}

    public function isEmpty(): bool
    {
        return $this->published === 0 && $this->failed === 0;
    }
}
