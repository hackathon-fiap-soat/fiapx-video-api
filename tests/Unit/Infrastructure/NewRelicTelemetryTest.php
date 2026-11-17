<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Application\Shared\Telemetry\VideoSagaTransition;
use App\Infrastructure\Observability\NewRelicTelemetry;
use PHPUnit\Framework\TestCase;

final class NewRelicTelemetryTest extends TestCase
{
    public function test_envia_os_eventos_customizados_com_os_atributos(): void
    {
        $calls = [];
        $telemetry = new NewRelicTelemetry(function (string $type, array $attributes) use (&$calls): void {
            $calls[] = [$type, $attributes];
        });

        $telemetry->recordTransition(new VideoSagaTransition('v', 's', 'c', 'u', 'PROCESSING', 'COMPENSATING', 'CleanupArtifacts', 15, 'PROCESSING', 'Timeout', 'PROCESSING_TIMEOUT'));
        $telemetry->recordTimeoutCheck(3, 2);

        self::assertSame('VideoSagaTransition', $calls[0][0]);
        self::assertSame(['video_id' => 'v', 'saga_id' => 's', 'correlation_id' => 'c', 'user_id' => 'u', 'from_state' => 'PROCESSING',
            'to_state' => 'COMPENSATING', 'step' => 'CleanupArtifacts', 'duration_ms' => 15, 'video_status' => 'PROCESSING',
            'trigger' => 'Timeout', 'failure_reason' => 'PROCESSING_TIMEOUT'], $calls[0][1]);
        self::assertSame(['SagaTimeoutCheck', ['overdue_count' => 3, 'compensated_count' => 2]], $calls[1]);
    }

    public function test_sem_o_agente_do_new_relic_e_no_op(): void
    {
        self::assertFalse(function_exists('newrelic_record_custom_event'), 'o agente não roda nos testes');
        $telemetry = new NewRelicTelemetry;
        $telemetry->recordTransition(new VideoSagaTransition('v', '', '', 'u', '', '', 'RequestVideoUpload', 0, 'PENDING_UPLOAD', 'POST /api/videos'));
        $telemetry->recordTimeoutCheck(0, 0);
        $this->addToAssertionCount(1);
    }
}
