<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Shared\Ports\MessageDeduplicator;
use App\Infrastructure\Persistence\Eloquent\Models\ProcessedMessageModel;
use App\Infrastructure\Support\Dates;

/** processed_messages: INSERT IGNORE na transação do efeito; 0 linhas inseridas = duplicata. */
final class DatabaseMessageDeduplicator implements MessageDeduplicator
{
    public function register(string $messageId, string $type): bool
    {
        return ProcessedMessageModel::query()->insertOrIgnore([
            'message_id' => $messageId, 'type' => mb_substr($type, 0, 60), 'processed_at' => Dates::toDb(new \DateTimeImmutable),
        ]) === 1;
    }

    public function pruneOlderThan(\DateTimeImmutable $limit): int
    {
        return ProcessedMessageModel::query()->where('processed_at', '<', Dates::toDb($limit))->delete();
    }
}
