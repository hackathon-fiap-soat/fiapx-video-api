<?php

declare(strict_types=1);

namespace App\Application\Messaging;

/** Filas de destino dos comandos (coluna outbox_messages.destination). */
enum Destination: string
{
    case ProcessingCommands = 'processing-commands';
    case NotificationCommands = 'notification-commands';
}
