<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Messaging\Destination;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Support\Dates;
use App\Infrastructure\Support\NewRelic;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * outbox-relay: publica a outbox no SQS. Seleciona as pendentes com FOR UPDATE SKIP LOCKED (várias
 * réplicas não pegam a mesma linha), envia e marca published_at. Se cair entre enviar e marcar, a mensagem
 * sai de novo e o consumidor descarta a duplicata pelo message_id.
 */
final readonly class OutboxRelay
{
    public function __construct(private SqsClient $sqs, private ParameterResolver $parameters, private LoggerInterface $log) {}

    /** Publica um lote; devolve quantas mensagens foram enviadas e quantas falharam. */
    public function publishBatch(int $limit = 10): OutboxBatchResult
    {
        return DB::transaction(function () use ($limit): OutboxBatchResult {
            $rows = DB::select(
                'SELECT id, destination, type, payload, attempts FROM outbox_messages
                 WHERE published_at IS NULL ORDER BY created_at, id LIMIT ? FOR UPDATE SKIP LOCKED',
                [$limit],
            );
            $sent = 0;
            $failed = 0;
            foreach ($rows as $row) {
                $envelope = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
                try {
                    $this->sqs->sendMessage([
                        'QueueUrl' => $this->queueUrl(Destination::from((string) $row->destination)),
                        'MessageBody' => (string) $row->payload,
                        'MessageAttributes' => [
                            'type' => ['DataType' => 'String', 'StringValue' => (string) $row->type],
                            'correlation_id' => ['DataType' => 'String', 'StringValue' => (string) $envelope['correlation_id']],
                        ],
                    ]);
                    DB::update('UPDATE outbox_messages SET published_at = ?, attempts = attempts + 1 WHERE id = ?', [Dates::toDb(new \DateTimeImmutable), $row->id]);
                    $sent++;
                    $this->log->info('comando publicado', ['message_id' => $row->id, 'type' => $row->type, 'destination' => $row->destination,
                        'saga_id' => $envelope['saga_id'], 'video_id' => $envelope['video_id'], 'correlation_id' => $envelope['correlation_id']]);
                } catch (\Throwable $e) {
                    DB::update('UPDATE outbox_messages SET attempts = LEAST(attempts + 1, 255) WHERE id = ?', [$row->id]);
                    $failed++;
                    NewRelic::noticeError($e);
                    $this->log->error('falha ao publicar comando', ['message_id' => $row->id, 'type' => $row->type, 'error' => $e->getMessage()]);
                }
            }

            return new OutboxBatchResult($sent, $failed);
        });
    }

    private function queueUrl(Destination $destination): string
    {
        return $this->parameters->get(match ($destination) {
            Destination::ProcessingCommands => 'processing_commands_queue_url',
            Destination::NotificationCommands => 'notification_commands_queue_url',
        });
    }
}
