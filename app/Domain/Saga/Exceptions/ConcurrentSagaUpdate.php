<?php

declare(strict_types=1);

namespace App\Domain\Saga\Exceptions;

/** Outro consumidor alterou a saga antes (lock otimista por version). A mensagem volta para a fila. */
final class ConcurrentSagaUpdate extends \RuntimeException {}
