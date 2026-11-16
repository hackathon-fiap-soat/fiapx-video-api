<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Illuminate\Http\Request;

/**
 * Usuário da requisição (ADR-004). O API Gateway já validou o token no authorizer e manda x-user-id,
 * x-user-email e x-user-name; mesmo assim o JWT do header Authorization é revalidado aqui (defesa em
 * profundidade) e os headers precisam bater com ele. Localmente, sem gateway, só o JWT é usado.
 */
final readonly class GatewayUserResolver
{
    public function __construct(private JwtValidator $validator) {}

    public function resolve(Request $request): AuthenticatedUser
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) !== 1) {
            throw new UnauthenticatedException('Header Authorization ausente ou mal formado.');
        }
        $user = $this->validator->validate($m[1]);

        $gatewayUserId = $request->header('x-user-id');
        if ($gatewayUserId !== null && $gatewayUserId !== $user->id) {
            throw new UnauthenticatedException('x-user-id diferente do sub do JWT.');
        }

        return $user;
    }
}
