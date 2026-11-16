<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Infrastructure\Auth\GatewayUserResolver;
use App\Infrastructure\Auth\UnauthenticatedException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Exige o JWT revalidado (ADR-004) e guarda o usuário em $request->attributes('user'). */
final readonly class AuthenticateGatewayUser
{
    public function __construct(private GatewayUserResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $this->resolver->resolve($request);
        } catch (UnauthenticatedException $e) {
            Log::info('requisição não autenticada', ['motivo' => $e->getMessage(), 'path' => $request->path()]);
            throw $e;
        }
        $request->attributes->set('user', $user);
        Log::withContext(['user_id' => $user->id]);

        return $next($request);
    }
}
