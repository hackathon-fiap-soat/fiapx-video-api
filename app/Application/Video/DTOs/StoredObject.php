<?php

declare(strict_types=1);

namespace App\Application\Video\DTOs;

/** Resultado do HeadObject. */
final readonly class StoredObject
{
    public function __construct(public int $sizeBytes, public string $contentType) {}
}
