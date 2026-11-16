<?php

declare(strict_types=1);

namespace App\Application\Shared\Ports;

use App\Application\Video\DTOs\DownloadLink;
use App\Application\Video\DTOs\StoredObject;
use App\Application\Video\DTOs\UploadTicket;
use App\Domain\Video\ValueObjects\StorageKey;

/** Bucket de vídeos (S3). */
interface ObjectStorage
{
    /** POST pré-assinado com Content-Type fixo e content-length-range de 1 a $maxBytes. */
    public function presignUpload(StorageKey $key, string $contentType, int $maxBytes, int $ttlSeconds): UploadTicket;

    /** HeadObject; null se o objeto não existe. */
    public function head(StorageKey $key): ?StoredObject;

    /** GET pré-assinado. */
    public function presignDownload(StorageKey $key, int $ttlSeconds): DownloadLink;

    /** Idempotente: apagar um objeto que não existe não é erro. */
    public function delete(StorageKey $key): void;
}
