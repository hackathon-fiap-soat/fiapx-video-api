<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Domain\Video\ValueObjects\VideoId;
use App\Domain\Video\ValueObjects\VideoStatus;
use App\Infrastructure\Persistence\Eloquent\Models\VideoModel;
use App\Infrastructure\Support\Dates;

final class EloquentVideoRepository implements VideoRepositoryInterface
{
    public function add(Video $video): void
    {
        VideoModel::query()->insert($this->toRow($video) + [
            'id' => $video->id->value,
            'user_id' => $video->owner->userId,
            'user_email' => $video->owner->email,
            'user_name' => $video->owner->name,
            'original_name' => $video->originalName,
            'content_type' => $video->contentType,
            'size_bytes' => $video->sizeBytes,
            'video_key' => $video->videoKey->value,
            'created_at' => Dates::toDb($video->createdAt),
        ]);
    }

    public function save(Video $video): void
    {
        VideoModel::query()->whereKey($video->id->value)->update($this->toRow($video));
    }

    public function find(VideoId $id): ?Video
    {
        return $this->hydrate(VideoModel::query()->find($id->value));
    }

    public function findForUpdate(VideoId $id): ?Video
    {
        return $this->hydrate(VideoModel::query()->whereKey($id->value)->lockForUpdate()->first());
    }

    public function findForUser(VideoId $id, string $userId): ?Video
    {
        return $this->hydrate(VideoModel::query()->whereKey($id->value)->where('user_id', $userId)->first());
    }

    public function paginateForUser(string $userId, ?VideoStatus $status, int $page, int $perPage): array
    {
        $query = VideoModel::query()->where('user_id', $userId)
            ->when($status !== null, fn ($q) => $q->where('status', $status?->value));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return ['items' => $rows->map(fn (VideoModel $m) => $this->hydrate($m))->filter()->values()->all(), 'total' => $total];
    }

    public function pendingUploadCreatedBefore(\DateTimeImmutable $limit, int $max): array
    {
        return VideoModel::query()->where('status', VideoStatus::PendingUpload->value)
            ->where('created_at', '<', Dates::toDb($limit))->orderBy('created_at')->limit($max)->get()
            ->map(fn (VideoModel $m) => $this->hydrate($m))->filter()->values()->all();
    }

    /** @return array<string, mixed> */
    private function toRow(Video $video): array
    {
        return [
            'status' => $video->status()->value,
            'zip_key' => $video->zipKey()?->value,
            'frame_count' => $video->frameCount(),
            'failure_code' => $video->failure()?->value,
            'failure_reason' => $video->failure()?->message(),
            'updated_at' => Dates::toDb($video->updatedAt()),
        ];
    }

    private function hydrate(?VideoModel $m): ?Video
    {
        if ($m === null) {
            return null;
        }

        return Video::restore(
            new VideoId($m->id),
            new Owner($m->user_id, $m->user_email, $m->user_name),
            $m->original_name,
            $m->content_type,
            (int) $m->size_bytes,
            StorageKey::fromString($m->video_key),
            VideoStatus::from($m->status),
            $m->zip_key !== null ? StorageKey::fromString($m->zip_key) : null,
            $m->frame_count !== null ? (int) $m->frame_count : null,
            $m->failure_code !== null ? FailureReason::tryFrom($m->failure_code) : null,
            Dates::fromDb($m->created_at),
            Dates::fromDb($m->updated_at),
        );
    }
}
