<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Messaging\MessageQueue;
use App\Infrastructure\Messaging\ReceivedMessage;
use Ramsey\Uuid\Uuid;

/**
 * Fila em memória. Como no SQS, a mensagem recebida fica invisível até ser confirmada (delete) ou devolvida
 * por releaseInFlight() (fim do visibility timeout).
 */
final class InMemoryQueue implements MessageQueue
{
    /** @var array<string, array<string, ReceivedMessage>> */
    public array $queues = [];

    /** @var array<string, array<string, ReceivedMessage>> */
    public array $inFlight = [];

    public function push(string $queueUrl, string $body, ?string $messageId = null): ReceivedMessage
    {
        $m = new ReceivedMessage($messageId ?? Uuid::uuid4()->toString(), Uuid::uuid4()->toString(), $body);
        $this->queues[$queueUrl][$m->receiptHandle] = $m;

        return $m;
    }

    public function receive(string $queueUrl, int $waitSeconds, int $max = 10): array
    {
        $batch = array_slice($this->queues[$queueUrl] ?? [], 0, $max, true);
        foreach ($batch as $handle => $m) {
            unset($this->queues[$queueUrl][$handle]);
            $this->inFlight[$queueUrl][$handle] = $m;
        }

        return array_values($batch);
    }

    public function delete(string $queueUrl, ReceivedMessage $message): void
    {
        unset($this->inFlight[$queueUrl][$message->receiptHandle], $this->queues[$queueUrl][$message->receiptHandle]);
    }

    /** Mensagens não confirmadas voltam a ficar visíveis. */
    public function releaseInFlight(): void
    {
        foreach ($this->inFlight as $url => $messages) {
            foreach ($messages as $handle => $m) {
                $this->queues[$url][$handle] = $m;
            }
        }
        $this->inFlight = [];
    }

    /** Mensagens ainda não confirmadas (visíveis ou em processamento). */
    public function pending(string $queueUrl): int
    {
        return count($this->queues[$queueUrl] ?? []) + count($this->inFlight[$queueUrl] ?? []);
    }
}
