<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

/** Recurso inexistente ou de outro usuário (→ 404 no handler global). */
final class NotFoundException extends \DomainException {}
