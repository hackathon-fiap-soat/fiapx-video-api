<?php

declare(strict_types=1);

namespace App\Application\Shared;

/** Parâmetros da saga e do upload (config/fiapx.php). */
final readonly class SagaSettings
{
    public function __construct(
        public string $bucket,
        public int $processingTimeoutSeconds = 1800,
        public bool $notifyOnCompleted = true,
        public int $uploadTtlSeconds = 3600,
        public int $downloadTtlSeconds = 900,
        public int $maxParallelPerUser = 5,
        public int $pendingUploadTtlSeconds = 3600,
        public int $retentionDays = 7,
    ) {}
}
