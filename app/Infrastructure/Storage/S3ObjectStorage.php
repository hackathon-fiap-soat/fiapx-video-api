<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Video\DTOs\DownloadLink;
use App\Application\Video\DTOs\StoredObject;
use App\Application\Video\DTOs\UploadTicket;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Infrastructure\Config\ParameterResolver;
use Aws\Exception\AwsException;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;

/**
 * Bucket de vídeos. Usa dois clientes: um para as chamadas do servidor (HeadObject, DeleteObject) e outro
 * só para assinar URLs que o navegador vai usar (no ambiente local, o LocalStack tem endereços diferentes
 * dentro e fora do Docker).
 */
final readonly class S3ObjectStorage implements ObjectStorage
{
    public function __construct(private S3Client $client, private S3Client $presigner, private ParameterResolver $parameters) {}

    public function presignUpload(StorageKey $key, string $contentType, int $maxBytes, int $ttlSeconds): UploadTicket
    {
        $bucket = $this->bucket();
        $post = new PostObjectV4(
            $this->presigner,
            $bucket,
            ['key' => $key->value, 'Content-Type' => $contentType],
            [['bucket' => $bucket], ['eq', '$key', $key->value], ['eq', '$Content-Type', $contentType], ['content-length-range', 1, $maxBytes]],
            "+{$ttlSeconds} seconds",
        );
        /** @var array<string, string> $fields */
        $fields = array_map('strval', $post->getFormInputs());

        return new UploadTicket((string) $post->getFormAttributes()['action'], $fields, (new \DateTimeImmutable)->modify("+{$ttlSeconds} seconds"));
    }

    public function head(StorageKey $key): ?StoredObject
    {
        try {
            $r = $this->client->headObject(['Bucket' => $this->bucket(), 'Key' => $key->value]);
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }

        return new StoredObject((int) $r['ContentLength'], (string) ($r['ContentType'] ?? ''));
    }

    public function presignDownload(StorageKey $key, int $ttlSeconds): DownloadLink
    {
        $command = $this->presigner->getCommand('GetObject', [
            'Bucket' => $this->bucket(),
            'Key' => $key->value,
            'ResponseContentDisposition' => 'attachment; filename="frames.zip"',
        ]);
        $request = $this->presigner->createPresignedRequest($command, "+{$ttlSeconds} seconds");

        return new DownloadLink((string) $request->getUri(), (new \DateTimeImmutable)->modify("+{$ttlSeconds} seconds"));
    }

    public function delete(StorageKey $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key->value]);
    }

    private function bucket(): string
    {
        return $this->parameters->get('bucket');
    }
}
