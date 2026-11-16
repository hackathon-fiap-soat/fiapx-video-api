<?php

declare(strict_types=1);

namespace App\Domain\Saga\Exceptions;

/**
 * Mensagem que não cabe no estado atual da saga (ex.: VideoProcessed em COMPENSATING). O consumidor
 * descarta a mensagem com log de warning, sem mandar para a DLQ (spec §6.4, guardas de transição).
 */
final class InvalidSagaTransition extends \DomainException {}
