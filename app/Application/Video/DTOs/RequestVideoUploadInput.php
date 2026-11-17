<?php

declare(strict_types=1);

namespace App\Application\Video\DTOs;

final readonly class RequestVideoUploadInput
{
    public function __construct(
        public string $userId,
        public string $userEmail,
        public string $userName,
        public string $filename,
        public int $sizeBytes,
        public string $contentType,
        public string $correlationId = '',
    ) {}
}
