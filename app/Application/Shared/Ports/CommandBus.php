<?php

declare(strict_types=1);

namespace App\Application\Shared\Ports;

use App\Application\Messaging\OutgoingMessage;

/** Envia comandos pela transactional outbox (gravados na transação corrente; publicados pelo outbox-relay). */
interface CommandBus
{
    public function dispatch(OutgoingMessage $message): void;
}
