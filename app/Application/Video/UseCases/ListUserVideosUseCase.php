<?php

declare(strict_types=1);

namespace App\Application\Video\UseCases;

use App\Application\Video\DTOs\VideoPage;
use App\Application\Video\DTOs\VideoView;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\VideoStatus;

/** GET /api/videos: só os vídeos do usuário do token, mais recentes primeiro. */
final readonly class ListUserVideosUseCase
{
    public function __construct(private VideoRepositoryInterface $videos) {}

    public function execute(string $userId, ?VideoStatus $status, int $page = 1, int $perPage = 10): VideoPage
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $result = $this->videos->paginateForUser($userId, $status, $page, $perPage);

        return new VideoPage(array_map(VideoView::fromEntity(...), $result['items']), $page, $perPage, $result['total']);
    }
}
