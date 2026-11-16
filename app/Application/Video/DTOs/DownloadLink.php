<?php

declare(strict_types=1);

namespace App\Application\Video\DTOs;

final readonly class DownloadLink
{
    public function __construct(public string $url, public \DateTimeImmutable $expiresAt) {}
}
