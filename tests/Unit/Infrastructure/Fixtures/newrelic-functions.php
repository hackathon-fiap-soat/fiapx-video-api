<?php

declare(strict_types=1);

// Funções do agente New Relic para os testes que rodam em processo separado: registram cada chamada em
// $GLOBALS['newrelic_calls'] para o teste conferir a ordem e os argumentos.

function newrelic_record(string $function, mixed ...$args): void
{
    $GLOBALS['newrelic_calls'][] = [$function, ...$args];
}

function newrelic_start_transaction(string $appname): bool
{
    newrelic_record('start_transaction', $appname);

    return true;
}

function newrelic_end_transaction(): bool
{
    newrelic_record('end_transaction');

    return true;
}

function newrelic_name_transaction(string $name): bool
{
    newrelic_record('name_transaction', $name);

    return true;
}

function newrelic_background_job(bool $flag = true): void
{
    newrelic_record('background_job', $flag);
}

function newrelic_add_custom_parameter(string $key, bool|float|int|string $value): bool
{
    newrelic_record('add_custom_parameter', $key, $value);

    return true;
}

function newrelic_ignore_transaction(): void
{
    newrelic_record('ignore_transaction');
}

function newrelic_notice_error(string $message, Throwable $e): void
{
    newrelic_record('notice_error', $message, $e::class);
}
