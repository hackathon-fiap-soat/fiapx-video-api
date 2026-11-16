<?php

declare(strict_types=1);

namespace App\Application\Saga\UseCases;

use App\Application\Messaging\MessageFactory;
use App\Application\Shared\Ports\CommandBus;
use App\Application\Shared\Ports\TransactionManager;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Shared\Interfaces\Clock;

/**
 * saga:check-timeouts (a cada minuto): sagas PROCESSING_REQUESTED ou PROCESSING com prazo vencido entram em
 * COMPENSATING com PROCESSING_TIMEOUT e enviam CleanupArtifacts. Cobre também o ProcessVideo que foi para a
 * DLQ antes de qualquer resposta. O comando chama timeOut() para cada id, um por transação.
 */
final readonly class CheckSagaTimeoutsUseCase
{
    public function __construct(
        private ProcessingSagaRepositoryInterface $sagas,
        private CommandBus $commands,
        private TransactionManager $transactions,
        private MessageFactory $messages,
        private Clock $clock,
    ) {}

    /** @return list<string> */
    public function expiredSagaIds(int $max = 100): array
    {
        return $this->sagas->idsWithExpiredDeadline($this->clock->now(), $max);
    }

    /** Compensa uma saga; false se ela já saiu do estado de espera (respondeu nesse meio-tempo). */
    public function timeOut(string $sagaId): bool
    {
        return $this->transactions->run(function () use ($sagaId): bool {
            $saga = $this->sagas->find($sagaId);
            $now = $this->clock->now();
            if ($saga === null || ! $saga->state()->awaitsProcessor() || $saga->deadline() === null || ! $saga->deadline()->isExpired($now)) {
                return false;
            }
            $saga->timeOut($now);
            $this->commands->dispatch($this->messages->cleanupArtifacts($saga));
            $this->sagas->save($saga);

            return true;
        });
    }
}
