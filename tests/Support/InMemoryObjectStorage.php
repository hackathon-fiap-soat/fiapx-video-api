<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Video\DTOs\DownloadLink;
use App\Application\Video\DTOs\StoredObject;
use App\Application\Video\DTOs\UploadTicket;
use App\Domain\Video\ValueObjects\StorageKey;

/** Bucket em memória para os testes HTTP e o BDD (o S3 real é coberto pela suíte Integration). */
final class InMemoryObjectStorage implements ObjectStorage
{
    /** @var array<string, StoredObject> */
    public array $objects = [];

    /** @var list<string> */
    public array $deleted = [];

    public function presignUpload(StorageKey $key, string $contentType, int $maxBytes, int $ttlSeconds): UploadTicket
    {
        return new UploadTicket('http://s3.teste/bucket', ['key' => $key->value, 'Content-Type' => $contentType, 'policy' => base64_encode("max={$maxBytes}")], (new \DateTimeImmutable)->modify("+{$ttlSeconds} seconds"));
    }

    public function head(StorageKey $key): ?StoredObject
    {
        return $this->objects[$key->value] ?? null;
    }

    public function presignDownload(StorageKey $key, int $ttlSeconds): DownloadLink
    {
        return new DownloadLink("http://s3.teste/bucket/{$key->value}?X-Amz-Expires={$ttlSeconds}", (new \DateTimeImmutable)->modify("+{$ttlSeconds} seconds"));
    }

    public function delete(StorageKey $key): void
    {
        unset($this->objects[$key->value]);
        $this->deleted[] = $key->value;
    }
}
