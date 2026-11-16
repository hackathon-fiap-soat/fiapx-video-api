<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

use App\Domain\Shared\ValueObjects\Uuid;

final readonly class VideoId
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = Uuid::assert($value, 'Id do vídeo');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
