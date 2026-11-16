<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Shared\Ports\CommandBus;
use App\Application\Shared\Ports\MessageDeduplicator;
use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Shared\Ports\OutboxHousekeeping;
use App\Application\Shared\Ports\TransactionManager;
use App\Application\Shared\SagaSettings;
use App\Domain\Quota\Interfaces\ProcessingQuotaRepositoryInterface;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Shared\Interfaces\IdGenerator;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Infrastructure\Auth\JwtValidator;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Messaging\MessageQueue;
use App\Infrastructure\Messaging\OutboxCommandBus;
use App\Infrastructure\Messaging\SqsQueue;
use App\Infrastructure\Persistence\DatabaseTransactionManager;
use App\Infrastructure\Persistence\Eloquent\Repositories\DatabaseMessageDeduplicator;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentProcessingQuotaRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentProcessingSagaRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentVideoRepository;
use App\Infrastructure\Storage\S3ObjectStorage;
use App\Infrastructure\Support\AwsClients;
use App\Infrastructure\Support\RamseyUuidV7Generator;
use App\Infrastructure\Support\SystemClock;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Aws\Ssm\SsmClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/** Liga cada interface do domínio e da aplicação à implementação da infraestrutura (ADR-003). */
final class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        VideoRepositoryInterface::class => EloquentVideoRepository::class,
        ProcessingSagaRepositoryInterface::class => EloquentProcessingSagaRepository::class,
        ProcessingQuotaRepositoryInterface::class => EloquentProcessingQuotaRepository::class,
        MessageDeduplicator::class => DatabaseMessageDeduplicator::class,
        TransactionManager::class => DatabaseTransactionManager::class,
        CommandBus::class => OutboxCommandBus::class,
        OutboxHousekeeping::class => OutboxCommandBus::class,
        Clock::class => SystemClock::class,
        IdGenerator::class => RamseyUuidV7Generator::class,
        ObjectStorage::class => S3ObjectStorage::class,
        MessageQueue::class => SqsQueue::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SsmClient::class, fn () => new SsmClient(AwsClients::options()));
        $this->app->singleton(SqsClient::class, fn () => new SqsClient(AwsClients::options()));
        $this->app->singleton(S3Client::class, fn () => new S3Client(AwsClients::options()));

        $this->app->singleton(ParameterResolver::class, fn (Application $app) => new ParameterResolver(
            fn () => $app->make(SsmClient::class),
            (string) config('fiapx.env'),
            (array) config('fiapx.parameters'),
        ));

        $this->app->singleton(S3ObjectStorage::class, fn (Application $app) => new S3ObjectStorage(
            $app->make(S3Client::class),
            new S3Client(AwsClients::options(config('fiapx.aws.public_endpoint'))),
            $app->make(ParameterResolver::class),
        ));

        $this->app->singleton(SagaSettings::class, fn (Application $app) => new SagaSettings(
            bucket: $app->make(ParameterResolver::class)->get('bucket'),
            processingTimeoutSeconds: (int) config('fiapx.saga.processing_timeout_seconds'),
            notifyOnCompleted: (bool) config('fiapx.saga.notify_on_completed'),
            uploadTtlSeconds: (int) config('fiapx.saga.upload_ttl_seconds'),
            downloadTtlSeconds: (int) config('fiapx.saga.download_ttl_seconds'),
            maxParallelPerUser: (int) config('fiapx.saga.max_parallel_per_user'),
            pendingUploadTtlSeconds: (int) config('fiapx.saga.pending_upload_ttl_seconds'),
            retentionDays: (int) config('fiapx.saga.retention_days'),
        ));

        $this->app->singleton(JwtValidator::class, fn (Application $app) => new JwtValidator(
            fn () => $app->make(ParameterResolver::class)->get('jwt_public_key'),
        ));
    }
}
