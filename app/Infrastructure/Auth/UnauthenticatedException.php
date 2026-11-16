<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/** Token ausente ou inválido (→ 401). O motivo vai só para o log. */
final class UnauthenticatedException extends \RuntimeException {}
