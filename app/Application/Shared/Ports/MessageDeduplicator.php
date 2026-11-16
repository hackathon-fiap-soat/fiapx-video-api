<?php

declare(strict_types=1);

namespace App\Application\Shared\Ports;

/** Idempotência dos consumidores (tabela processed_messages, gravada na mesma transação do efeito). */
interface MessageDeduplicator
{
    /** Registra a mensagem; false se ela já foi processada (duplicata → no-op). */
    public function register(string $messageId, string $type): bool;

    /** Apaga registros anteriores ao limite; devolve quantos. */
    public function pruneOlderThan(\DateTimeImmutable $limit): int;
}
