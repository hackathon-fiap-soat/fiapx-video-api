<?php

declare(strict_types=1);

namespace App\Application\Video\DTOs;

final readonly class VideoUploadCreated
{
    public function __construct(public string $id, public string $status, public UploadTicket $upload) {}
}
