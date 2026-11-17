<?php

declare(strict_types=1);

namespace App\Application\Shared\Ports;

use App\Application\Shared\Telemetry\VideoSagaTransition;

/**
 * Eventos customizados de observabilidade (fiapx-docs/07-observabilidade/observabilidade.md, "Eventos
 * customizados"). Os casos de uso chamam depois do commit da transação; a implementação não pode interromper o
 * fluxo de negócio.
 */
interface Telemetry
{
    /** VideoSagaTransition: uma mudança de estado da saga ou de status do vídeo. */
    public function recordTransition(VideoSagaTransition $transition): void;

    /** SagaTimeoutCheck: uma execução do saga:check-timeouts. */
    public function recordTimeoutCheck(int $overdueCount, int $compensatedCount): void;
}
