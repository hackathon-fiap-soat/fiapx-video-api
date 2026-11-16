<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

/** Tamanho do vídeo em bytes, de 1 byte a 500 MB. */
final readonly class FileSize
{
    public const MAX_BYTES = 524_288_000;

    public function __construct(public int $bytes)
    {
        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('O arquivo deve ter no máximo 500 MB.');
        }
    }

    public static function fits(int $bytes): bool
    {
        return $bytes >= 1 && $bytes <= self::MAX_BYTES;
    }
}
