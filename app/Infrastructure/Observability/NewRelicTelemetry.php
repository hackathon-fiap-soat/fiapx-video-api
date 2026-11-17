<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Application\Shared\Ports\Telemetry;
use App\Application\Shared\Telemetry\VideoSagaTransition;

/**
 * Eventos customizados pelo agente PHP do New Relic (newrelic_record_custom_event). Sem a extensão (ambiente
 * local, testes, NEW_RELIC_ENABLED=false sem o agente carregado) é um no-op.
 */
final readonly class NewRelicTelemetry implements Telemetry
{
    public const TIMEOUT_CHECK_EVENT = 'SagaTimeoutCheck';

    /** @var (\Closure(string, array<string, string|int>): mixed)|null */
    private ?\Closure $recorder;

    /** @param (\Closure(string, array<string, string|int>): mixed)|null $recorder só para testes */
    public function __construct(?\Closure $recorder = null)
    {
        $this->recorder = $recorder ?? (function_exists('newrelic_record_custom_event')
            ? \Closure::fromCallable('newrelic_record_custom_event')
            : null);
    }

    public function recordTransition(VideoSagaTransition $transition): void
    {
        $this->record(VideoSagaTransition::EVENT_TYPE, $transition->attributes());
    }

    public function recordTimeoutCheck(int $overdueCount, int $compensatedCount): void
    {
        $this->record(self::TIMEOUT_CHECK_EVENT, ['overdue_count' => $overdueCount, 'compensated_count' => $compensatedCount]);
    }

    /** @param array<string, string|int> $attributes */
    private function record(string $type, array $attributes): void
    {
        if ($this->recorder !== null) {
            ($this->recorder)($type, $attributes);
        }
    }
}
