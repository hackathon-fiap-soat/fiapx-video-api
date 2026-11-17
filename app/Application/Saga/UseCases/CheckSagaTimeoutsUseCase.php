<?php

declare(strict_types=1);

namespace App\Application\Saga\UseCases;

use App\Application\Messaging\MessageFactory;
use App\Application\Shared\Ports\CommandBus;
use App\Application\Shared\Ports\Telemetry;
use App\Application\Shared\Ports\TransactionManager;
use App\Application\Shared\Telemetry\VideoSagaTransition;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;

/**
 * saga:check-timeouts (a cada minuto): sagas PROCESSING_REQUESTED ou PROCESSING com prazo vencido entram em
 * COMPENSATING com PROCESSING_TIMEOUT e enviam CleanupArtifacts. Cobre também o ProcessVideo que foi para a
 * DLQ antes de qualquer resposta. O comando conta as vencidas (overdueCount), chama timeOut() para cada id, um
 * por transação, e fecha a execução com finishCheck() (evento SagaTimeoutCheck).
 */
final readonly class CheckSagaTimeoutsUseCase
{
    public const TRIGGER = 'Timeout';

    public function __construct(
        private ProcessingSagaRepositoryInterface $sagas,
        private VideoRepositoryInterface $videos,
        private CommandBus $commands,
        private TransactionManager $transactions,
        private MessageFactory $messages,
        private Clock $clock,
        private Telemetry $telemetry,
    ) {}

    /** Sagas aguardando o processor com prazo vencido no início da execução (overdue_count). */
    public function overdueCount(): int
    {
        return $this->sagas->countWithExpiredDeadline($this->clock->now());
    }

    /** @return list<string> */
    public function expiredSagaIds(int $max = 100): array
    {
        return $this->sagas->idsWithExpiredDeadline($this->clock->now(), $max);
    }

    /**
     * Compensa uma saga; false se ela já saiu do estado de espera (respondeu nesse meio-tempo). Depois do
     * commit, registra o VideoSagaTransition com trigger Timeout.
     */
    public function timeOut(string $sagaId): bool
    {
        $transitions = [];
        $compensated = $this->transactions->run(function () use ($sagaId, &$transitions): bool {
            $saga = $this->sagas->find($sagaId);
            $now = $this->clock->now();
            if ($saga === null || ! $saga->state()->awaitsProcessor() || $saga->deadline() === null || ! $saga->deadline()->isExpired($now)) {
                return false;
            }
            $historyBefore = count($saga->history());
            $saga->timeOut($now);
            $this->commands->dispatch($this->messages->cleanupArtifacts($saga));
            $this->sagas->save($saga);

            $video = $this->videos->find($saga->videoId);
            if ($video !== null) {
                $transitions = VideoSagaTransition::fromSaga($saga, $video, $historyBefore, self::TRIGGER);
            }

            return true;
        });

        foreach ($transitions as $transition) {
            $this->telemetry->recordTransition($transition);
        }

        return $compensated;
    }

    /** Fim de uma execução do saga:check-timeouts: evento SagaTimeoutCheck. */
    public function finishCheck(int $overdueCount, int $compensatedCount): void
    {
        $this->telemetry->recordTimeoutCheck($overdueCount, $compensatedCount);
    }
}
