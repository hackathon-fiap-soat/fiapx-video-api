<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Shared\Ports\Telemetry;
use App\Application\Shared\Telemetry\VideoSagaTransition;

/** Telemetria em memória: guarda os eventos como o New Relic receberia (tipo + atributos). */
final class FakeTelemetry implements Telemetry
{
    /** @var list<array{type: string, attributes: array<string, string|int>}> */
    public array $events = [];

    public function recordTransition(VideoSagaTransition $transition): void
    {
        $this->events[] = ['type' => VideoSagaTransition::EVENT_TYPE, 'attributes' => $transition->attributes()];
    }

    public function recordTimeoutCheck(int $overdueCount, int $compensatedCount): void
    {
        $this->events[] = ['type' => 'SagaTimeoutCheck', 'attributes' => ['overdue_count' => $overdueCount, 'compensated_count' => $compensatedCount]];
    }

    /** @return list<array<string, string|int>> atributos dos VideoSagaTransition, em ordem */
    public function transitions(): array
    {
        return array_values(array_map(
            fn (array $e) => $e['attributes'],
            array_filter($this->events, fn (array $e) => $e['type'] === VideoSagaTransition::EVENT_TYPE),
        ));
    }

    /** @return list<string> "from → to (step, trigger)" de cada transição, para comparar sequências */
    public function summary(): array
    {
        return array_map(fn (array $a) => "{$a['from_state']} → {$a['to_state']} ({$a['step']}, {$a['trigger']})", $this->transitions());
    }
}
