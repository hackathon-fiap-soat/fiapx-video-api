<?php

use App\Domain\Shared\Exceptions\ConflictException;
use App\Domain\Shared\Exceptions\NotFoundException;
use App\Infrastructure\Auth\UnauthenticatedException;
use App\Presentation\Http\Middleware\AuthenticateGatewayUser;
use App\Presentation\Http\Middleware\CorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
 * API-only. Handler global de exceções (ADR-003): controllers e use cases não têm try/catch.
 * Formato de erro do contrato: {message, errors?} (fiapx-docs/api/openapi.yaml).
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands([__DIR__.'/../app/Presentation/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [CorrelationId::class]);
        $middleware->alias(['gateway.user' => AuthenticateGatewayUser::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        // Respostas 4xx esperadas não são erro da aplicação: não vão para o log de erros.
        $exceptions->dontReport([
            UnauthenticatedException::class, NotFoundException::class, ConflictException::class,
            InvalidArgumentException::class, DomainException::class,
        ]);

        $json = fn (int $status, string $message, array $extra = []): JsonResponse => response()->json(['message' => $message] + $extra, $status);

        $exceptions->render(fn (UnauthenticatedException $e) => $json(401, 'Unauthorized'));
        $exceptions->render(fn (NotFoundException $e) => $json(404, $e->getMessage()));
        $exceptions->render(fn (ConflictException $e) => $json(409, $e->getMessage()));
        $exceptions->render(fn (ValidationException $e) => $json(422, 'Os dados informados são inválidos.', ['errors' => $e->errors()]));
        // VO inválido (formato, tamanho, nome) e regra de negócio: 422.
        $exceptions->render(fn (InvalidArgumentException $e) => $json(422, 'Os dados informados são inválidos.', ['errors' => ['request' => [$e->getMessage()]]]));
        $exceptions->render(fn (DomainException $e) => $json(422, $e->getMessage()));
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($json) {
            return $e->getStatusCode() === 404 ? $json(404, 'Recurso não encontrado.') : null;
        });
    })->create();
