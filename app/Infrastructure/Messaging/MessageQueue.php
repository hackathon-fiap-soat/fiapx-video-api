<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** Fila de mensagens (SQS na AWS e no LocalStack; em memória nos testes do consumidor). */
interface MessageQueue
{
    /** @return list<ReceivedMessage> */
    public function receive(string $queueUrl, int $waitSeconds, int $max = 10): array;

    public function delete(string $queueUrl, ReceivedMessage $message): void;
}
