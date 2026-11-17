<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Support\NewRelic;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class NewRelicTest extends TestCase
{
    public function test_sem_o_agente_do_new_relic_e_no_op(): void
    {
        self::assertFalse(function_exists('newrelic_start_transaction'), 'o agente não roda nos testes');
        NewRelic::startTransaction('OutboxRelay/publish', ['a' => 1]);
        NewRelic::addAttributes(['published_count' => 1]);
        NewRelic::nameTransaction('x');
        NewRelic::noticeError(new \RuntimeException('falha'));
        NewRelic::ignoreTransaction();
        NewRelic::endTransaction();
        $this->addToAssertionCount(1);
    }

    /** Com o agente (funções simuladas, num processo separado para não vazar para os outros testes). */
    #[RunInSeparateProcess]
    public function test_com_o_agente_abre_nomeia_e_fecha_a_transacao_de_background(): void
    {
        require __DIR__.'/Fixtures/newrelic-functions.php';
        $GLOBALS['newrelic_calls'] = [];
        ini_set('newrelic.appname', 'fiapx-video-api');

        NewRelic::startTransaction('OutboxRelay/publish', ['sqs_message_id' => 'm-1']);
        NewRelic::addAttributes(['published_count' => 3, 'failed_count' => 0]);
        NewRelic::nameTransaction('SagaConsumer/VideoProcessed');
        NewRelic::noticeError(new \RuntimeException('falha'));
        NewRelic::ignoreTransaction();
        NewRelic::endTransaction();

        self::assertSame([
            ['end_transaction'],
            ['start_transaction', (string) ini_get('newrelic.appname')],
            ['name_transaction', 'OutboxRelay/publish'],
            ['background_job', true],
            ['add_custom_parameter', 'sqs_message_id', 'm-1'],
            ['add_custom_parameter', 'published_count', 3],
            ['add_custom_parameter', 'failed_count', 0],
            ['name_transaction', 'SagaConsumer/VideoProcessed'],
            ['notice_error', 'falha', \RuntimeException::class],
            ['ignore_transaction'],
            ['end_transaction'],
        ], $GLOBALS['newrelic_calls']);
    }
}
