<?php

declare(strict_types=1);

namespace App\Application\Saga\DTOs;

/** Resultado de uma mensagem tratada pela saga (para logs e testes). */
enum SagaOutcome: string
{
    case Started = 'started';
    case Rejected = 'rejected';
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
}
