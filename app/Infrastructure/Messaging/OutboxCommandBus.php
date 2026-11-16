<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Messaging\OutgoingMessage;
use App\Application\Shared\Ports\CommandBus;
use App\Application\Shared\Ports\OutboxHousekeeping;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxMessageModel;
use App\Infrastructure\Support\Dates;

/** Grava o comando em outbox_messages, na transação corrente. O outbox-relay publica depois. */
final class OutboxCommandBus implements CommandBus, OutboxHousekeeping
{
    public function dispatch(OutgoingMessage $message): void
    {
        OutboxMessageModel::query()->insert([
            'id' => $message->messageId(),
            'destination' => $message->destination->value,
            'type' => $message->type(),
            'payload' => json_encode($message->envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'created_at' => Dates::toDb(new \DateTimeImmutable),
        ]);
    }

    public function prunePublishedBefore(\DateTimeImmutable $limit): int
    {
        return OutboxMessageModel::query()->whereNotNull('published_at')->where('published_at', '<', Dates::toDb($limit))->delete();
    }
}
