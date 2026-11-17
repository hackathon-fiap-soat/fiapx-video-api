<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

/**
 * Transações do agente New Relic (ADR-011) para os processos que não terminam: no saga:consume cada mensagem, e
 * no outbox:relay cada ciclo que publicou algo, vira uma transação de background. Sem isso, os eventos
 * customizados (VideoSagaTransition) e as métricas ficariam presos numa transação que só acaba com o processo.
 * Sem o agente (ambiente local, testes), não faz nada.
 */
final class NewRelic
{
    /** @param array<string, scalar> $attributes */
    public static function startTransaction(string $name, array $attributes = []): void
    {
        if (! function_exists('newrelic_start_transaction')) {
            return;
        }
        newrelic_end_transaction();
        newrelic_start_transaction((string) ini_get('newrelic.appname'));
        newrelic_name_transaction($name);
        newrelic_background_job(true);
        self::addAttributes($attributes);
    }

    /** @param array<string, scalar> $attributes */
    public static function addAttributes(array $attributes): void
    {
        if (! function_exists('newrelic_add_custom_parameter')) {
            return;
        }
        foreach ($attributes as $key => $value) {
            newrelic_add_custom_parameter($key, $value);
        }
    }

    /** Descarta a transação atual (ciclo ocioso de um processo contínuo): nada dela é enviado. */
    public static function ignoreTransaction(): void
    {
        if (function_exists('newrelic_ignore_transaction')) {
            newrelic_ignore_transaction();
        }
    }

    public static function nameTransaction(string $name): void
    {
        if (function_exists('newrelic_name_transaction')) {
            newrelic_name_transaction($name);
        }
    }

    public static function noticeError(\Throwable $e): void
    {
        if (function_exists('newrelic_notice_error')) {
            newrelic_notice_error($e->getMessage(), $e);
        }
    }

    public static function endTransaction(): void
    {
        if (function_exists('newrelic_end_transaction')) {
            newrelic_end_transaction();
        }
    }
}
