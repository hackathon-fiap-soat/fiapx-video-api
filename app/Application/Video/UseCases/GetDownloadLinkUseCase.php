<?php

declare(strict_types=1);

namespace App\Application\Video\UseCases;

use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Shared\SagaSettings;
use App\Application\Video\DTOs\DownloadLink;
use App\Domain\Shared\Exceptions\ConflictException;
use App\Domain\Shared\Exceptions\NotFoundException;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\VideoId;
use App\Domain\Video\ValueObjects\VideoStatus;

/** GET /api/videos/{id}/download: URL pré-assinada do zip (15 min), só para vídeo COMPLETED. */
final readonly class GetDownloadLinkUseCase
{
    public function __construct(private VideoRepositoryInterface $videos, private ObjectStorage $storage, private SagaSettings $settings) {}

    public function execute(string $userId, string $videoId): DownloadLink
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $videoId) !== 1) {
            throw new NotFoundException('Vídeo não encontrado.');
        }
        $video = $this->videos->findForUser(new VideoId($videoId), $userId);
        if ($video === null) {
            throw new NotFoundException('Vídeo não encontrado.');
        }
        $zip = $video->zipKey();
        if ($video->status() !== VideoStatus::Completed || $zip === null) {
            throw new ConflictException('O vídeo ainda não está pronto para download.');
        }

        return $this->storage->presignDownload($zip, $this->settings->downloadTtlSeconds);
    }
}
