<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Messaging\IncomingReply;
use App\Application\Saga\UseCases\HandleSagaReplyUseCase;
use App\Application\Saga\UseCases\StartProcessingSagaUseCase;
use App\Domain\Saga\Exceptions\InvalidSagaTransition;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Messaging\MessageQueue;
use App\Infrastructure\Messaging\ReceivedMessage;
use App\Infrastructure\Messaging\S3EventParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * saga-consumer: long polling nas filas video-uploaded e saga-replies.fifo. DeleteMessage só depois do
 * commit. É o único lugar com try/catch em volta dos casos de uso, porque precisa decidir entre confirmar
 * (ack) e deixar a mensagem voltar para a fila (retry → DLQ depois de N tentativas).
 */
final class SagaConsumeCommand extends Command
{
    protected $signature = 'saga:consume {--once : processa o que houver nas filas e sai} {--wait= : segundos de long polling}';

    protected $description = 'Consome os eventos do S3 e as respostas da SAGA';

    private bool $running = true;

    public function handle(MessageQueue $queue, ParameterResolver $parameters, S3EventParser $parser, StartProcessingSagaUseCase $start, HandleSagaReplyUseCase $replies): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });
        $uploads = $parameters->get('video_uploaded_queue_url');
        $sagaReplies = $parameters->get('saga_replies_queue_url');
        $wait = $this->option('wait') !== null ? (int) $this->option('wait') : (int) config('fiapx.consumer.wait_seconds');

        do {
            $handled = 0;
            foreach ($queue->receive($uploads, $wait, (int) config('fiapx.consumer.max_messages')) as $message) {
                $handled++;
                $this->consume($queue, $uploads, $message, function () use ($parser, $start, $message): array {
                    $outcomes = [];
                    foreach ($parser->parse($message->messageId, $message->body) as $event) {
                        $outcomes[] = $start->execute($event)->value.':'.$event->key;
                    }

                    return ['outcome' => $outcomes === [] ? 's3:TestEvent' : implode(',', $outcomes)];
                });
            }
            foreach ($queue->receive($sagaReplies, $wait, (int) config('fiapx.consumer.max_messages')) as $message) {
                $handled++;
                $this->consume($queue, $sagaReplies, $message, function () use ($replies, $message): array {
                    $reply = IncomingReply::fromEnvelope((array) json_decode($message->body, true));
                    Log::withContext(['saga_id' => $reply->sagaId, 'video_id' => $reply->videoId, 'correlation_id' => $reply->correlationId]);

                    return ['type' => $reply->type, 'outcome' => $replies->execute($reply)->value];
                });
            }
        } while ($this->running && ! ($this->option('once') && $handled === 0));

        return self::SUCCESS;
    }

    /** @param callable(): array<string, mixed> $handler */
    private function consume(MessageQueue $queue, string $queueUrl, ReceivedMessage $message, callable $handler): void
    {
        $context = ['sqs_message_id' => $message->messageId];
        try {
            Log::info('mensagem tratada', $context + $handler());
            $queue->delete($queueUrl, $message);
        } catch (InvalidSagaTransition $e) {
            // Guarda de transição: descarta com warning, sem DLQ (spec §6.4).
            Log::warning('resposta fora de ordem descartada', $context + ['motivo' => $e->getMessage()]);
            $queue->delete($queueUrl, $message);
        } catch (\InvalidArgumentException $e) {
            // Mensagem mal formada nunca vai funcionar: deixa ir para a DLQ após as tentativas, com erro no log.
            Log::error('mensagem inválida', $context + ['motivo' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Falha transitória (banco, S3, lock otimista): a mensagem volta para a fila.
            Log::error('falha ao tratar mensagem; ela volta para a fila', $context + ['error' => $e->getMessage(), 'exception' => $e::class]);
        } finally {
            Log::withoutContext(['saga_id', 'video_id', 'correlation_id']);
        }
    }
}
