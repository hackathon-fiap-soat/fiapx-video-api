<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Infrastructure\Config\ParameterResolver;
use Aws\Sqs\SqsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Probes do Kubernetes e smoke test pelo gateway (sem authorizer). */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Testa o banco fiapx_video e o SQS (GetQueueAttributes na fila video-uploaded). */
    public function ready(SqsClient $sqs, ParameterResolver $parameters): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1')),
            'sqs' => $this->check(fn () => $sqs->getQueueAttributes([
                'QueueUrl' => $parameters->get('video_uploaded_queue_url'), 'AttributeNames' => ['QueueArn'],
            ])),
        ];
        $ok = ! in_array('fail', $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (\Throwable $e) {
            Log::warning('readiness falhou', ['error' => $e->getMessage()]);

            return 'fail';
        }
    }
}
