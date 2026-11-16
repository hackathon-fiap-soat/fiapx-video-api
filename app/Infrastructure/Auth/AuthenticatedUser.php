<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/** Usuário do JWT revalidado. */
final readonly class AuthenticatedUser
{
    public function __construct(public string $id, public string $email, public string $name) {}
}
