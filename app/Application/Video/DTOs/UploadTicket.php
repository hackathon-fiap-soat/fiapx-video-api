<?php

declare(strict_types=1);

namespace App\Application\Video\DTOs;

/** POST pré-assinado do S3. */
final readonly class UploadTicket
{
    /** @param array<string, string> $fields */
    public function __construct(public string $url, public array $fields, public \DateTimeImmutable $expiresAt) {}
}
