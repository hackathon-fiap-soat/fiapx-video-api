<?php

declare(strict_types=1);

namespace App\Domain\Quota\Entities;

use App\Domain\Quota\Exceptions\QuotaExceeded;
use App\Domain\Shared\ValueObjects\Uuid;

/** Cota de processamentos simultâneos por usuário (padrão: 5). */
final class ProcessingQuota
{
    public const DEFAULT_MAX_PARALLEL = 5;

    private function __construct(
        public readonly string $userId,
        private int $inProgress,
        public readonly int $maxParallel,
    ) {}

    public static function forNewUser(string $userId, int $maxParallel = self::DEFAULT_MAX_PARALLEL): self
    {
        return new self(Uuid::assert($userId, 'Id do usuário'), 0, $maxParallel);
    }

    public static function restore(string $userId, int $inProgress, int $maxParallel): self
    {
        return new self($userId, max(0, $inProgress), $maxParallel);
    }

    public function canReserve(): bool
    {
        return $this->inProgress < $this->maxParallel;
    }

    public function reserve(): void
    {
        if (! $this->canReserve()) {
            throw new QuotaExceeded("Limite de {$this->maxParallel} vídeos em processamento atingido.");
        }
        $this->inProgress++;
    }

    /** Libera uma vaga; nunca fica negativo (liberação repetida não quebra a contagem). */
    public function release(): void
    {
        $this->inProgress = max(0, $this->inProgress - 1);
    }

    public function inProgress(): int
    {
        return $this->inProgress;
    }
}
