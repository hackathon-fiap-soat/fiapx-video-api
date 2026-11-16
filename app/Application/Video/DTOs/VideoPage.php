<?php

declare(strict_types=1);

namespace App\Application\Video\DTOs;

final readonly class VideoPage
{
    /** @param list<VideoView> $items */
    public function __construct(public array $items, public int $page, public int $perPage, public int $total) {}
}
