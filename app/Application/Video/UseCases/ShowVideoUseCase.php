<?php

declare(strict_types=1);

namespace App\Application\Video\UseCases;

use App\Application\Video\DTOs\VideoView;
use App\Domain\Shared\Exceptions\NotFoundException;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\VideoId;

/** GET /api/videos/{id}: vídeo de outro usuário responde 404, como se não existisse. */
final readonly class ShowVideoUseCase
{
    public function __construct(private VideoRepositoryInterface $videos) {}

    public function execute(string $userId, string $videoId): VideoView
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $videoId) !== 1) {
            throw new NotFoundException('Vídeo não encontrado.');
        }
        $video = $this->videos->findForUser(new VideoId($videoId), $userId);
        if ($video === null) {
            throw new NotFoundException('Vídeo não encontrado.');
        }

        return VideoView::fromEntity($video);
    }
}
