<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

/** Ciclo de vida do vídeo (spec §5.3). Finais: COMPLETED, FAILED e EXPIRED. */
enum VideoStatus: string
{
    case PendingUpload = 'PENDING_UPLOAD';
    case Uploaded = 'UPLOADED';
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Expired], true);
    }

    /** Transições permitidas a partir deste status. */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::PendingUpload => [self::Uploaded, self::Expired],
            self::Uploaded => [self::Queued, self::Failed],
            // QUEUED → COMPLETED: VideoProcessed sem ProcessingStarted antes (saga.md, "Ordem das respostas").
            self::Queued => [self::Processing, self::Completed, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed, self::Failed, self::Expired => [],
        }, true);
    }
}
