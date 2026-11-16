<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Auth\JwtValidator;
use App\Infrastructure\Config\ParameterResolver;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Foundation\Application;

/** Par de chaves RSA de teste e emissão de tokens no formato do fiapx-auth. */
final class JwtFactory
{
    /** @var array{private: string, public: string}|null */
    private static ?array $keys = null;

    /** @return array{private: string, public: string} */
    public static function keys(): array
    {
        if (self::$keys === null) {
            $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($res, $private);
            self::$keys = ['private' => $private, 'public' => openssl_pkey_get_details($res)['key']];
        }

        return self::$keys;
    }

    /** Faz a aplicação validar tokens com a chave pública de teste. */
    public static function install(Application $app): void
    {
        config(['fiapx.parameters.jwt_public_key' => [self::keys()['public'], 'auth/jwt_public_key']]);
        $app->forgetInstance(ParameterResolver::class);
        $app->forgetInstance(JwtValidator::class);
    }

    /** @param array<string, mixed> $override */
    public static function token(string $userId, string $email = 'ana@exemplo.com', string $name = 'Ana', array $override = []): string
    {
        return JWT::encode($override + [
            'sub' => $userId, 'email' => $email, 'name' => $name, 'iss' => JwtValidator::ISSUER,
            'aud' => JwtValidator::AUDIENCE, 'iat' => time(), 'exp' => time() + 3600,
        ], self::keys()['private'], 'RS256');
    }
}
