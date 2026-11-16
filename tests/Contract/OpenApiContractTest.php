<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Application\Shared\Ports\ObjectStorage;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\VideoId;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentVideoRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Contracts;
use Tests\Support\InMemoryObjectStorage;
use Tests\Support\JwtFactory;
use Tests\TestCase;

/** As respostas reais da API validam contra os schemas de fiapx-docs/api/openapi.yaml. */
final class OpenApiContractTest extends TestCase
{
    use DatabaseTransactions;

    private const USER = '0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6c';

    protected function setUp(): void
    {
        parent::setUp();
        JwtFactory::install($this->app);
        $this->app->instance(ObjectStorage::class, new InMemoryObjectStorage);
    }

    public function test_respostas_validam_no_openapi(): void
    {
        $auth = ['Authorization' => 'Bearer '.JwtFactory::token(self::USER)];
        $created = $this->postJson('/api/videos', ['filename' => 'a.mp4', 'size_bytes' => 10, 'content_type' => 'video/mp4'], $auth)->json();
        self::assertSame([], Contracts::openApiErrors($created, 'VideoUploadCreated'));

        $repo = new EloquentVideoRepository;
        $video = $repo->find(new VideoId($created['id']));
        $video->markUploaded(new \DateTimeImmutable);
        $video->fail(FailureReason::QuotaExceeded, new \DateTimeImmutable);
        $repo->save($video);

        self::assertSame([], Contracts::openApiErrors($this->getJson('/api/videos', $auth)->json(), 'VideoPage'));
        self::assertSame([], Contracts::openApiErrors($this->getJson('/api/videos/'.$created['id'], $auth)->json(), 'Video'));
        self::assertSame([], Contracts::openApiErrors($this->getJson('/api/videos/'.$created['id'].'/download', $auth)->json(), 'Error'));
        self::assertSame([], Contracts::openApiErrors($this->postJson('/api/videos', [], $auth)->json(), 'Error'));
        self::assertSame([], Contracts::openApiErrors($this->getJson('/api/health/ready')->json(), 'Health'));
    }
}
