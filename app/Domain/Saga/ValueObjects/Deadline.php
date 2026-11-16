<?php

declare(strict_types=1);

namespace App\Domain\Saga\ValueObjects;

/** Prazo para o processador responder (padrão: 30 minutos). */
final readonly class Deadline
{
    public const DEFAULT_SECONDS = 1800;

    public function __construct(public \DateTimeImmutable $at) {}

    public static function from(\DateTimeImmutable $now, int $seconds = self::DEFAULT_SECONDS): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('O prazo deve ser positivo.');
        }

        return new self($now->modify("+{$seconds} seconds"));
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->at;
    }
}
