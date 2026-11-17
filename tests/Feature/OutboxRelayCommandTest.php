<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Messaging\OutboxBatchResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * outbox:relay ciclo a ciclo (cada um é uma transação do New Relic; no-op sem o agente): lote com falha de
 * envio e falha do lote inteiro não derrubam o processo. A publicação real no SQS está no SagaFlowTest.
 */
final class OutboxRelayCommandTest extends TestCase
{
    public function test_falha_ao_publicar_uma_mensagem_conta_a_tentativa_e_segue(): void
    {
        $id = (string) Uuid::uuid7();
        $envelope = ['message_id' => $id, 'type' => 'SendNotification', 'version' => 1, 'saga_id' => $id, 'video_id' => $id, 'correlation_id' => $id, 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'payload' => []];
        // Destino desconhecido: o envio falha antes de chegar ao SQS.
        DB::table('outbox_messages')->insert(['id' => $id, 'destination' => 'destino-inexistente', 'type' => 'SendNotification', 'payload' => json_encode($envelope), 'created_at' => now()]);
        try {
            $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

            $row = DB::table('outbox_messages')->where('id', $id)->first();
            self::assertNotNull($row);
            self::assertNull($row->published_at);
            self::assertSame(1, (int) $row->attempts);
        } finally {
            DB::table('outbox_messages')->where('id', $id)->delete();
        }
    }

    public function test_ciclo_sem_mensagens_termina_com_once(): void
    {
        DB::shouldReceive('transaction')->once()->andReturn(new OutboxBatchResult);

        $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    }

    public function test_falha_do_lote_e_registrada_e_o_relay_nao_cai(): void
    {
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('banco fora'));
        Log::shouldReceive('error')->once()->with('falha no outbox-relay', ['error' => 'banco fora']);

        $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    }
}
