<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Saga\UseCases\CheckSagaTimeoutsUseCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** saga:check-timeouts (agendado a cada minuto). Uma saga com erro não impede as outras. */
final class SagaCheckTimeoutsCommand extends Command
{
    protected $signature = 'saga:check-timeouts';

    protected $description = 'Compensa as sagas com prazo de processamento vencido';

    public function handle(CheckSagaTimeoutsUseCase $useCase): int
    {
        $compensated = 0;
        foreach ($useCase->expiredSagaIds() as $id) {
            try {
                if ($useCase->timeOut($id)) {
                    $compensated++;
                    Log::warning('saga com prazo vencido: compensação iniciada', ['saga_id' => $id, 'failure_code' => 'PROCESSING_TIMEOUT']);
                }
            } catch (\Throwable $e) {
                Log::error('falha ao compensar saga vencida', ['saga_id' => $id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("{$compensated} saga(s) compensada(s) por timeout.");

        return self::SUCCESS;
    }
}
