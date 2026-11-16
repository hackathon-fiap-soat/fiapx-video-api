<?php

declare(strict_types=1);

namespace App\Domain\Video\Interfaces;

use App\Domain\Video\Entities\Video;
use App\Domain\Video\ValueObjects\VideoId;
use App\Domain\Video\ValueObjects\VideoStatus;

interface VideoRepositoryInterface
{
    public function add(Video $video): void;

    public function save(Video $video): void;

    public function find(VideoId $id): ?Video;

    /** Bloqueia a linha até o fim da transação (usado pela saga). */
    public function findForUpdate(VideoId $id): ?Video;

    /** Vídeo do usuário; null se não existe ou é de outro usuário. */
    public function findForUser(VideoId $id, string $userId): ?Video;

    /**
     * Página de vídeos do usuário, do mais recente para o mais antigo.
     *
     * @return array{items: list<Video>, total: int}
     */
    public function paginateForUser(string $userId, ?VideoStatus $status, int $page, int $perPage): array;

    /**
     * Vídeos em PENDING_UPLOAD criados antes do limite.
     *
     * @return list<Video>
     */
    public function pendingUploadCreatedBefore(\DateTimeImmutable $limit, int $max): array;
}
