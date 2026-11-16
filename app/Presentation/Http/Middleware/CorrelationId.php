<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\Shared\Interfaces\IdGenerator;
use App\Domain\Shared\ValueObjects\Uuid;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Propaga o x-correlation-id (ou gera um UUID v7) nos logs e na resposta. */
final readonly class CorrelationId
{
    public function __construct(private IdGenerator $ids) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = strtolower(trim((string) $request->header('x-correlation-id', '')));
        $id = Uuid::isValid($header) ? $header : $this->ids->next();
        $request->attributes->set('correlation_id', $id);
        Log::withContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set('x-correlation-id', $id);

        return $response;
    }
}
