<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\Video\DTOs\RequestVideoUploadInput;
use App\Application\Video\UseCases\GetDownloadLinkUseCase;
use App\Application\Video\UseCases\ListUserVideosUseCase;
use App\Application\Video\UseCases\RequestVideoUploadUseCase;
use App\Application\Video\UseCases\ShowVideoUseCase;
use App\Domain\Video\ValueObjects\VideoStatus;
use App\Infrastructure\Auth\AuthenticatedUser;
use App\Presentation\Http\Requests\ListVideosRequest;
use App\Presentation\Http\Requests\RequestVideoUploadRequest;
use App\Presentation\Http\Resources\VideoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Só converte HTTP ↔ DTO; sem try/catch (o handler global mapeia as exceções). */
final class VideoController
{
    public function store(RequestVideoUploadRequest $request, RequestVideoUploadUseCase $useCase): JsonResponse
    {
        $user = $this->user($request);
        $out = $useCase->execute(new RequestVideoUploadInput(
            $user->id, $user->email, $user->name,
            (string) $request->validated('filename'), (int) $request->validated('size_bytes'), (string) $request->validated('content_type'),
        ));

        return response()->json([
            'id' => $out->id,
            'status' => $out->status,
            'upload' => ['url' => $out->upload->url, 'fields' => $out->upload->fields, 'expires_at' => VideoResource::date($out->upload->expiresAt)],
        ], 201);
    }

    public function index(ListVideosRequest $request, ListUserVideosUseCase $useCase): JsonResponse
    {
        $status = $request->validated('status');
        $page = $useCase->execute(
            $this->user($request)->id,
            $status !== null ? VideoStatus::from((string) $status) : null,
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? 10),
        );

        return response()->json([
            'data' => array_map(VideoResource::toArray(...), $page->items),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total],
        ]);
    }

    public function show(Request $request, string $id, ShowVideoUseCase $useCase): JsonResponse
    {
        return response()->json(VideoResource::toArray($useCase->execute($this->user($request)->id, $id)));
    }

    public function download(Request $request, string $id, GetDownloadLinkUseCase $useCase): JsonResponse
    {
        $link = $useCase->execute($this->user($request)->id, $id);

        return response()->json(['url' => $link->url, 'expires_at' => VideoResource::date($link->expiresAt)]);
    }

    private function user(Request $request): AuthenticatedUser
    {
        /** @var AuthenticatedUser */
        return $request->attributes->get('user');
    }
}
