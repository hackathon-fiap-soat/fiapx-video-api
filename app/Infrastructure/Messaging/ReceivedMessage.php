<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

final readonly class ReceivedMessage
{
    public function __construct(public string $messageId, public string $receiptHandle, public string $body) {}
}
