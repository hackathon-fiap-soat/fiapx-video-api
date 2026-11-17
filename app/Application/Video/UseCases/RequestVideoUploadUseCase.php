<?php

declare(strict_types=1);

namespace App\Application\Video\UseCases;

use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Shared\Ports\Telemetry;
use App\Application\Shared\SagaSettings;
use App\Application\Shared\Telemetry\VideoSagaTransition;
use App\Application\Video\DTOs\RequestVideoUploadInput;
use App\Application\Video\DTOs\VideoUploadCreated;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Shared\Interfaces\IdGenerator;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FileName;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\VideoId;

/**
 * POST /api/videos: registra o vídeo em PENDING_UPLOAD e devolve o POST pré-assinado do S3. O tamanho
 * declarado é o limite do content-length-range: o S3 recusa um arquivo maior (ADR-006). Depois de gravar,
 * registra o VideoSagaTransition da criação (step RequestVideoUpload, sem saga).
 */
final readonly class RequestVideoUploadUseCase
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private ObjectStorage $storage,
        private IdGenerator $ids,
        private Clock $clock,
        private SagaSettings $settings,
        private Telemetry $telemetry,
    ) {}

    public function execute(RequestVideoUploadInput $input): VideoUploadCreated
    {
        $video = Video::create(
            new VideoId($this->ids->next()),
            new Owner($input->userId, $input->userEmail, $input->userName),
            new FileName($input->filename),
            new FileSize($input->sizeBytes),
            $input->contentType,
            $this->clock->now(),
        );
        $ticket = $this->storage->presignUpload($video->videoKey, $video->contentType, $video->sizeBytes, $this->settings->uploadTtlSeconds);
        $this->videos->add($video);
        $this->telemetry->recordTransition(VideoSagaTransition::videoCreated($video, $input->correlationId));

        return new VideoUploadCreated($video->id->value, $video->status()->value, $ticket);
    }
}
