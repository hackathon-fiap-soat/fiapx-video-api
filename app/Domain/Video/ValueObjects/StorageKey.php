<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

/** Convenção de chaves no S3 (fiapx-docs/contracts/README.md). */
final readonly class StorageKey
{
    private function __construct(public string $value) {}

    /** uploads/{video_id}.{ext} */
    public static function upload(VideoId $id, VideoFormat $format): self
    {
        return new self("uploads/{$id->value}.{$format->value}");
    }

    /** results/{video_id}/ */
    public static function resultsPrefix(VideoId $id): self
    {
        return new self("results/{$id->value}/");
    }

    /** results/{video_id}/frames.zip */
    public static function framesZip(VideoId $id): self
    {
        return new self("results/{$id->value}/frames.zip");
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /** Id do vídeo de uma chave uploads/{video_id}.{ext}; null se a chave não segue a convenção. */
    public static function videoIdFromUploadKey(string $key): ?VideoId
    {
        if (preg_match('#^uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.[^/.]+$#', $key, $m) !== 1) {
            return null;
        }

        return new VideoId($m[1]);
    }

    /** Extensão da chave, se for uma das aceitas. */
    public function format(): ?VideoFormat
    {
        return VideoFormat::tryFrom(strtolower(pathinfo($this->value, PATHINFO_EXTENSION)));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
