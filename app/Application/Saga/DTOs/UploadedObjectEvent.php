<?php

declare(strict_types=1);

namespace App\Application\Saga\DTOs;

/**
 * Um registro ObjectCreated do evento do S3 (fiapx-docs/contracts/s3-object-created.schema.json), com a
 * chave já decodificada. eventId = MessageId da mensagem SQS que trouxe o evento: é o UUID usado como
 * correlation_id da saga.
 */
final readonly class UploadedObjectEvent
{
    public function __construct(public string $eventId, public string $bucket, public string $key, public int $sizeBytes) {}
}
