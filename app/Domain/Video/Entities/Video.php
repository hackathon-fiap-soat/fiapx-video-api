<?php

declare(strict_types=1);

namespace App\Domain\Video\Entities;

use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\FileName;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Domain\Video\ValueObjects\VideoId;
use App\Domain\Video\ValueObjects\VideoStatus;

/**
 * Agregado Vídeo. Só muda de status pelas transições do ciclo de vida (spec §5.3); qualquer outra é
 * recusada com \DomainException.
 */
final class Video
{
    private function __construct(
        public readonly VideoId $id,
        public readonly Owner $owner,
        public readonly string $originalName,
        public readonly string $contentType,
        public readonly int $sizeBytes,
        public readonly StorageKey $videoKey,
        private VideoStatus $status,
        private ?StorageKey $zipKey,
        private ?int $frameCount,
        private ?FailureReason $failure,
        public readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {}

    /** Novo vídeo aguardando o envio do arquivo ao S3. */
    public static function create(VideoId $id, Owner $owner, FileName $name, FileSize $size, string $contentType, \DateTimeImmutable $now): self
    {
        $contentType = trim($contentType);
        if (mb_strlen($contentType) > 100) {
            throw new \InvalidArgumentException('Tipo do arquivo inválido.');
        }

        return new self(
            $id, $owner, $name->value, $contentType === '' ? $name->format->defaultMimeType() : $contentType,
            $size->bytes, StorageKey::upload($id, $name->format), VideoStatus::PendingUpload,
            null, null, null, $now, $now,
        );
    }

    /** Reconstrói a partir da persistência, sem validar transições. */
    public static function restore(
        VideoId $id, Owner $owner, string $originalName, string $contentType, int $sizeBytes, StorageKey $videoKey,
        VideoStatus $status, ?StorageKey $zipKey, ?int $frameCount, ?FailureReason $failure,
        \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $owner, $originalName, $contentType, $sizeBytes, $videoKey, $status, $zipKey, $frameCount, $failure, $createdAt, $updatedAt);
    }

    public function markUploaded(\DateTimeImmutable $now): void
    {
        $this->transition(VideoStatus::Uploaded, $now);
    }

    public function markQueued(\DateTimeImmutable $now): void
    {
        $this->transition(VideoStatus::Queued, $now);
    }

    public function markProcessing(\DateTimeImmutable $now): void
    {
        $this->transition(VideoStatus::Processing, $now);
    }

    public function complete(StorageKey $zipKey, int $frameCount, \DateTimeImmutable $now): void
    {
        if ($frameCount < 1) {
            throw new \InvalidArgumentException('A quantidade de frames deve ser maior que zero.');
        }
        $this->transition(VideoStatus::Completed, $now);
        $this->zipKey = $zipKey;
        $this->frameCount = $frameCount;
    }

    public function fail(FailureReason $reason, \DateTimeImmutable $now): void
    {
        $this->transition(VideoStatus::Failed, $now);
        $this->failure = $reason;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->transition(VideoStatus::Expired, $now);
    }

    public function belongsTo(string $userId): bool
    {
        return $this->owner->userId === $userId;
    }

    public function status(): VideoStatus
    {
        return $this->status;
    }

    public function zipKey(): ?StorageKey
    {
        return $this->zipKey;
    }

    public function frameCount(): ?int
    {
        return $this->frameCount;
    }

    public function failure(): ?FailureReason
    {
        return $this->failure;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function transition(VideoStatus $next, \DateTimeImmutable $now): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new \DomainException("Transição de vídeo inválida: {$this->status->value} → {$next->value}.");
        }
        $this->status = $next;
        $this->updatedAt = $now;
    }
}
