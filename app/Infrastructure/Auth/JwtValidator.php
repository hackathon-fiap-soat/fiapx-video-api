<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Valida o JWT com as mesmas regras do authorizer do fiapx-auth (fiapx-docs/contracts/jwt/README.md):
 * só RS256, iss = fiapx-auth, aud = fiapx-api, exp obrigatório, tolerância de 30 s e claims sub, email e
 * name. Testado pelos mesmos vetores do Node (tests/Contract/JwtVectorsTest.php).
 */
final class JwtValidator
{
    public const ISSUER = 'fiapx-auth';

    public const AUDIENCE = 'fiapx-api';

    public const LEEWAY_SECONDS = 30;

    /** @param \Closure(): string $publicKey */
    public function __construct(private readonly \Closure $publicKey) {}

    public function validate(string $token): AuthenticatedUser
    {
        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            // Key com 'RS256': a biblioteca recusa qualquer outro alg do header (none, HS256...).
            $claims = (array) JWT::decode($token, new Key(($this->publicKey)(), 'RS256'));
        } catch (\Throwable $e) {
            throw new UnauthenticatedException('JWT inválido: '.$e->getMessage(), 0, $e);
        }

        $aud = $claims['aud'] ?? null;
        $audOk = is_array($aud) ? in_array(self::AUDIENCE, $aud, true) : $aud === self::AUDIENCE;
        if (($claims['iss'] ?? null) !== self::ISSUER || ! $audOk) {
            throw new UnauthenticatedException('JWT com iss ou aud inválido.');
        }
        if (! isset($claims['exp']) || ! is_numeric($claims['exp'])) {
            throw new UnauthenticatedException('JWT sem exp.');
        }
        foreach (['sub', 'email', 'name'] as $claim) {
            if (! isset($claims[$claim]) || ! is_string($claims[$claim]) || $claims[$claim] === '') {
                throw new UnauthenticatedException("JWT sem a claim {$claim}.");
            }
        }

        return new AuthenticatedUser($claims['sub'], $claims['email'], $claims['name']);
    }
}
