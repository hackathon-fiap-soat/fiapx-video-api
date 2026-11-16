<?php

declare(strict_types=1);

namespace App\Application\Shared\Ports;

interface TransactionManager
{
    /**
     * Executa a função numa transação: commit se terminar, rollback se lançar exceção.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function run(callable $fn): mixed;
}
