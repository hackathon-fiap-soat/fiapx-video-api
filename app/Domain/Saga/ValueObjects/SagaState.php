<?php

declare(strict_types=1);

namespace App\Domain\Saga\ValueObjects;

/** Estados da saga (fiapx-docs/03-arquitetura/saga.md). */
enum SagaState: string
{
    case Started = 'STARTED';
    case UploadValidated = 'UPLOAD_VALIDATED';
    case QuotaReserved = 'QUOTA_RESERVED';
    case ProcessingRequested = 'PROCESSING_REQUESTED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Compensating = 'COMPENSATING';
    case Compensated = 'COMPENSATED';
    case Notified = 'NOTIFIED';

    /** Estados em que o processador está trabalhando e o prazo vale. */
    public function awaitsProcessor(): bool
    {
        return $this === self::ProcessingRequested || $this === self::Processing;
    }
}
