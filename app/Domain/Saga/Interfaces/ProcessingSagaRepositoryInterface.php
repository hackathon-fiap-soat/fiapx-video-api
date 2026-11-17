<?php

declare(strict_types=1);

namespace App\Domain\Saga\Interfaces;

use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\Exceptions\ConcurrentSagaUpdate;
use App\Domain\Video\ValueObjects\VideoId;

interface ProcessingSagaRepositoryInterface
{
    public function add(ProcessingSaga $saga): void;

    /**
     * Grava as mudanças só se a versão no banco ainda for a lida (lock otimista).
     *
     * @throws ConcurrentSagaUpdate
     */
    public function save(ProcessingSaga $saga): void;

    public function find(string $id): ?ProcessingSaga;

    public function findByVideo(VideoId $videoId): ?ProcessingSaga;

    /**
     * Ids das sagas PROCESSING_REQUESTED ou PROCESSING com prazo vencido.
     *
     * @return list<string>
     */
    public function idsWithExpiredDeadline(\DateTimeImmutable $now, int $max): array;

    /** Quantidade de sagas PROCESSING_REQUESTED ou PROCESSING com prazo vencido. */
    public function countWithExpiredDeadline(\DateTimeImmutable $now): int;
}
