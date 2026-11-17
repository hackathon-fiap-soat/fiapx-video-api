<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\Exceptions\ConcurrentSagaUpdate;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Saga\ValueObjects\Deadline;
use App\Domain\Saga\ValueObjects\SagaState;
use App\Domain\Saga\ValueObjects\SagaStep;
use App\Domain\Video\ValueObjects\VideoId;
use App\Infrastructure\Persistence\Eloquent\Models\ProcessingSagaModel;
use App\Infrastructure\Support\Dates;
use Illuminate\Database\Eloquent\Builder;

final class EloquentProcessingSagaRepository implements ProcessingSagaRepositoryInterface
{
    public function add(ProcessingSaga $saga): void
    {
        $now = Dates::toDb(new \DateTimeImmutable);
        ProcessingSagaModel::query()->insert($this->toRow($saga) + [
            'id' => $saga->id,
            'video_id' => $saga->videoId->value,
            'correlation_id' => $saga->correlationId,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Lock otimista: só grava se a versão no banco ainda for a lida. */
    public function save(ProcessingSaga $saga): void
    {
        $updated = ProcessingSagaModel::query()
            ->whereKey($saga->id)
            ->where('version', $saga->version())
            ->update($this->toRow($saga) + ['version' => $saga->version() + 1, 'updated_at' => Dates::toDb(new \DateTimeImmutable)]);
        if ($updated !== 1) {
            throw new ConcurrentSagaUpdate("A saga {$saga->id} foi alterada por outro consumidor.");
        }
    }

    public function find(string $id): ?ProcessingSaga
    {
        return $this->hydrate(ProcessingSagaModel::query()->find($id));
    }

    public function findByVideo(VideoId $videoId): ?ProcessingSaga
    {
        return $this->hydrate(ProcessingSagaModel::query()->where('video_id', $videoId->value)->first());
    }

    public function idsWithExpiredDeadline(\DateTimeImmutable $now, int $max): array
    {
        return $this->expiredDeadline($now)
            ->orderBy('deadline_at')->limit($max)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function countWithExpiredDeadline(\DateTimeImmutable $now): int
    {
        return $this->expiredDeadline($now)->count();
    }

    /** @return Builder<ProcessingSagaModel> */
    private function expiredDeadline(\DateTimeImmutable $now): Builder
    {
        return ProcessingSagaModel::query()
            ->whereIn('state', [SagaState::ProcessingRequested->value, SagaState::Processing->value])
            ->where('deadline_at', '<=', Dates::toDb($now));
    }

    /** @return array<string, mixed> */
    private function toRow(ProcessingSaga $saga): array
    {
        return [
            'state' => $saga->state()->value,
            'current_step' => $saga->currentStep()->value,
            'deadline_at' => $saga->deadline() !== null ? Dates::toDb($saga->deadline()->at) : null,
            'attempts' => min(255, $saga->attempts()),
            'history' => json_encode($saga->history(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function hydrate(?ProcessingSagaModel $m): ?ProcessingSaga
    {
        if ($m === null) {
            return null;
        }
        $deadline = Dates::fromDbNullable($m->deadline_at);

        return ProcessingSaga::restore(
            $m->id,
            new VideoId($m->video_id),
            $m->correlation_id,
            SagaState::from($m->state),
            SagaStep::from($m->current_step),
            $deadline !== null ? new Deadline($deadline) : null,
            (int) $m->attempts,
            json_decode((string) $m->history, true, 512, JSON_THROW_ON_ERROR),
            (int) $m->version,
        );
    }
}
