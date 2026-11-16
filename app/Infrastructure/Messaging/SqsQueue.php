<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Aws\Sqs\SqsClient;

/** Long polling e confirmação (DeleteMessage) no SQS. */
final readonly class SqsQueue implements MessageQueue
{
    public function __construct(private SqsClient $sqs) {}

    /** @return list<ReceivedMessage> */
    public function receive(string $queueUrl, int $waitSeconds, int $max = 10): array
    {
        $result = $this->sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'WaitTimeSeconds' => $waitSeconds,
            'MaxNumberOfMessages' => $max,
            'MessageAttributeNames' => ['All'],
        ]);

        return array_map(
            fn (array $m) => new ReceivedMessage((string) $m['MessageId'], (string) $m['ReceiptHandle'], (string) $m['Body']),
            $result['Messages'] ?? [],
        );
    }

    public function delete(string $queueUrl, ReceivedMessage $message): void
    {
        $this->sqs->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message->receiptHandle]);
    }
}
