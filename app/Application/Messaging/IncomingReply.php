<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Domain\Shared\ValueObjects\Uuid;

/**
 * Resposta recebida na saga-replies.fifo. Consumidor tolerante: lê só os campos que usa e ignora os
 * desconhecidos.
 */
final readonly class IncomingReply
{
    public const TYPES = ['ProcessingStarted', 'VideoProcessed', 'VideoProcessingFailed', 'ArtifactsCleaned', 'NotificationSent', 'NotificationFailed'];

    /** @param array<string, mixed> $payload */
    private function __construct(
        public string $messageId,
        public string $type,
        public int $version,
        public string $sagaId,
        public string $videoId,
        public string $correlationId,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope
     *
     * @throws \InvalidArgumentException envelope sem os campos obrigatórios ou tipo desconhecido
     */
    public static function fromEnvelope(array $envelope): self
    {
        $type = (string) ($envelope['type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de resposta desconhecido: '{$type}'.");
        }
        $payload = $envelope['payload'] ?? null;
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Envelope sem payload.');
        }

        return new self(
            Uuid::assert((string) ($envelope['message_id'] ?? ''), 'message_id'),
            $type,
            (int) ($envelope['version'] ?? 1),
            Uuid::assert((string) ($envelope['saga_id'] ?? ''), 'saga_id'),
            Uuid::assert((string) ($envelope['video_id'] ?? ''), 'video_id'),
            Uuid::assert((string) ($envelope['correlation_id'] ?? ''), 'correlation_id'),
            $payload,
        );
    }
}
