<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Saga\DTOs\UploadedObjectEvent;
use Ramsey\Uuid\Uuid;

/**
 * Converte a notificação do S3 (fiapx-docs/contracts/s3-object-created.schema.json) em eventos de upload.
 * Consumidor tolerante: lê só bucket, chave e tamanho. O s3:TestEvent, enviado quando a notificação é
 * configurada, devolve lista vazia (descartado sem erro).
 */
final class S3EventParser
{
    /**
     * @return list<UploadedObjectEvent>
     *
     * @throws \InvalidArgumentException corpo que não é uma notificação do S3
     */
    public function parse(string $sqsMessageId, string $body): array
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('Mensagem da fila video-uploaded não é JSON.');
        }
        if (($data['Event'] ?? null) === 's3:TestEvent') {
            return [];
        }
        if (! isset($data['Records']) || ! is_array($data['Records'])) {
            throw new \InvalidArgumentException('Notificação do S3 sem Records.');
        }

        $events = [];
        foreach (array_values($data['Records']) as $i => $record) {
            if (($record['eventSource'] ?? null) !== 'aws:s3' || ! str_starts_with((string) ($record['eventName'] ?? ''), 'ObjectCreated:')) {
                continue;
            }
            $key = rawurldecode(str_replace('+', ' ', (string) ($record['s3']['object']['key'] ?? '')));
            // O id da mensagem SQS é o "id do evento" (correlation_id). Com mais de um registro, os demais
            // ganham um UUID v5 derivado dele, estável entre reentregas.
            $eventId = $i === 0 ? strtolower($sqsMessageId) : Uuid::uuid5(Uuid::NAMESPACE_URL, "{$sqsMessageId}#{$i}")->toString();
            $events[] = new UploadedObjectEvent(
                $eventId,
                (string) ($record['s3']['bucket']['name'] ?? ''),
                $key,
                (int) ($record['s3']['object']['size'] ?? 0),
            );
        }

        return $events;
    }
}
