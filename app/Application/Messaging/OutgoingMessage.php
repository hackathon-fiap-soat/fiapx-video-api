<?php

declare(strict_types=1);

namespace App\Application\Messaging;

/** Comando pronto para a outbox: destino e envelope completo (fiapx-docs/contracts/envelope.schema.json). */
final readonly class OutgoingMessage
{
    /** @param array<string, mixed> $envelope */
    public function __construct(public Destination $destination, public array $envelope) {}

    public function messageId(): string
    {
        return (string) $this->envelope['message_id'];
    }

    public function type(): string
    {
        return (string) $this->envelope['type'];
    }
}
