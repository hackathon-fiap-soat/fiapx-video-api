<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Application\Video\DTOs\VideoView;

/** Schema Video do OpenAPI. */
final class VideoResource
{
    /** @return array<string, mixed> */
    public static function toArray(VideoView $v): array
    {
        return [
            'id' => $v->id,
            'original_name' => $v->originalName,
            'size_bytes' => $v->sizeBytes,
            'status' => $v->status,
            'frame_count' => $v->frameCount,
            'failure_code' => $v->failureCode,
            'failure_reason' => $v->failureReason,
            'created_at' => self::date($v->createdAt),
            'updated_at' => self::date($v->updatedAt),
        ];
    }

    public static function date(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
