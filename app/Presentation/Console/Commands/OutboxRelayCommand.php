<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Infrastructure\Messaging\OutboxRelay;
use App\Infrastructure\Support\NewRelic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * outbox-relay: publica a outbox no SQS em lotes, com pausa curta quando não há nada pendente. Cada ciclo é uma
 * transação de background no New Relic (OutboxRelay/publish); os ciclos sem mensagem são descartados.
 */
final class OutboxRelayCommand extends Command
{
    public const TRANSACTION = 'OutboxRelay/publish';

    protected $signature = 'outbox:relay {--once : publica o que estiver pendente e sai}';

    protected $description = 'Publica os comandos da outbox no SQS';

    private bool $running = true;

    public function handle(OutboxRelay $relay): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });
        $batch = (int) config('fiapx.relay.batch_size');
        do {
            $sent = $this->cycle($relay, $batch);
            if ($this->option('once') && $sent < $batch) {
                break;
            }
            if ($sent === 0) {
                usleep((int) config('fiapx.relay.idle_sleep_ms') * 1000);
            }
        } while ($this->running);

        return self::SUCCESS;
    }

    /** Um lote numa transação do New Relic; devolve quantas mensagens foram publicadas. */
    private function cycle(OutboxRelay $relay, int $batch): int
    {
        NewRelic::startTransaction(self::TRANSACTION);
        try {
            $result = $relay->publishBatch($batch);
            if ($result->isEmpty()) {
                NewRelic::ignoreTransaction();
            } else {
                NewRelic::addAttributes(['published_count' => $result->published, 'failed_count' => $result->failed]);
            }

            return $result->published;
        } catch (\Throwable $e) {
            Log::error('falha no outbox-relay', ['error' => $e->getMessage()]);
            NewRelic::noticeError($e);

            return 0;
        } finally {
            NewRelic::endTransaction();
        }
    }
}
