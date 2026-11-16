<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

/** Regra de negócio que depende do estado atual do recurso, como baixar um vídeo não concluído (→ 409). */
final class ConflictException extends \DomainException {}
