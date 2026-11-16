<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Infrastructure\Auth\JwtValidator;
use App\Infrastructure\Auth\UnauthenticatedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Contracts;

/**
 * O validador do Laravel aceita e recusa exatamente os tokens de fiapx-docs/contracts/jwt/vetores.json,
 * os mesmos que o authorizer do fiapx-auth (Node) roda. Assim os dois validam o JWT do mesmo jeito.
 */
final class JwtVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function data(): array
    {
        return json_decode((string) file_get_contents(Contracts::dir('contracts/jwt/vetores.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_regras_do_contrato_batem_com_o_validador(): void
    {
        $r = self::data()['regras'];
        self::assertSame('RS256', $r['alg']);
        self::assertSame(JwtValidator::ISSUER, $r['iss']);
        self::assertSame(JwtValidator::AUDIENCE, $r['aud']);
        self::assertSame(JwtValidator::LEEWAY_SECONDS, $r['tolerancia_relogio_s']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vetores(): iterable
    {
        foreach (self::data()['vetores'] as $v) {
            yield $v['nome'] => [$v];
        }
    }

    #[DataProvider('vetores')]
    public function test_vetor(array $vetor): void
    {
        $chave = (string) file_get_contents(Contracts::dir('contracts/jwt/'.self::data()['chave_publica']));
        $validator = new JwtValidator(fn () => $chave);
        if ($vetor['valido']) {
            $user = $validator->validate($vetor['token']);
            self::assertSame($vetor['contexto'], ['user_id' => $user->id, 'email' => $user->email, 'name' => $user->name]);
        } else {
            $this->expectException(UnauthenticatedException::class);
            $validator->validate($vetor['token']);
        }
    }
}
