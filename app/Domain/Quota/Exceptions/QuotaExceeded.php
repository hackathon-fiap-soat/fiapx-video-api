<?php

declare(strict_types=1);

namespace App\Domain\Quota\Exceptions;

/** O usuário já está no limite de processamentos simultâneos. */
final class QuotaExceeded extends \DomainException {}
