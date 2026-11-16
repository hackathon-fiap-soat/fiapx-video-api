<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Infrastructure\Messaging\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** outbox-relay: publica a outbox no SQS em lotes, com pausa curta quando não há nada pendente. */
final class OutboxRelayCommand extends Command
{
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
            try {
                $sent = $relay->publishBatch($batch);
            } catch (\Throwable $e) {
                Log::error('falha no outbox-relay', ['error' => $e->getMessage()]);
                $sent = 0;
            }
            if ($this->option('once') && $sent < $batch) {
                break;
            }
            if ($sent === 0) {
                usleep((int) config('fiapx.relay.idle_sleep_ms') * 1000);
            }
        } while ($this->running);

        return self::SUCCESS;
    }
}
