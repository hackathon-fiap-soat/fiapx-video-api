<?php

declare(strict_types=1);

namespace App\Application\Video\DTOs;

use App\Domain\Video\Entities\Video;

/** Vídeo como a API o apresenta (schema Video do OpenAPI). */
final readonly class VideoView
{
    public function __construct(
        public string $id,
        public string $originalName,
        public int $sizeBytes,
        public string $status,
        public ?int $frameCount,
        public ?string $failureCode,
        public ?string $failureReason,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    public static function fromEntity(Video $video): self
    {
        return new self(
            $video->id->value, $video->originalName, $video->sizeBytes, $video->status()->value,
            $video->frameCount(), $video->failure()?->value, $video->failure()?->message(),
            $video->createdAt, $video->updatedAt(),
        );
    }
}
