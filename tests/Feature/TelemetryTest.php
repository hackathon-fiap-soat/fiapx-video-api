<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Shared\Ports\Telemetry;
use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Saga\Interfaces\ProcessingSagaRepositoryInterface;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FileName;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\VideoId;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeTelemetry;
use Tests\Support\InMemoryObjectStorage;
use Tests\Support\JwtFactory;
use Tests\TestCase;

/** Eventos customizados do New Relic pelos pontos de entrada reais (HTTP e comando), contra o MySQL local. */
final class TelemetryTest extends TestCase
{
    use DatabaseTransactions;

    private const USER = '0192f1c4-6a00-7b00-8c00-00000000beef';

    private FakeTelemetry $telemetry;

    protected function setUp(): void
    {
        parent::setUp();
        JwtFactory::install($this->app);
        $this->app->instance(ObjectStorage::class, new InMemoryObjectStorage);
        $this->telemetry = new FakeTelemetry;
        $this->app->instance(Telemetry::class, $this->telemetry);
    }

    public function test_post_de_video_emite_a_criacao_com_o_correlation_id_da_requisicao(): void
    {
        $correlation = '0192f1c4-0000-7000-8000-00000000c0de';
        $id = $this->postJson('/api/videos', ['filename' => 'aula.mp4', 'size_bytes' => 1024, 'content_type' => 'video/mp4'],
            ['Authorization' => 'Bearer '.JwtFactory::token(self::USER), 'x-correlation-id' => $correlation])->assertCreated()->json('id');

        $t = $this->telemetry->transitions();
        self::assertCount(1, $t);
        self::assertSame([$id, $correlation, self::USER, 'RequestVideoUpload', 'PENDING_UPLOAD', ''],
            [$t[0]['video_id'], $t[0]['correlation_id'], $t[0]['user_id'], $t[0]['step'], $t[0]['video_status'], $t[0]['saga_id']]);
    }

    public function test_check_timeouts_emite_a_compensacao_e_o_saga_timeout_check(): void
    {
        $videoId = Uuid::uuid7()->toString();
        $now = new \DateTimeImmutable;
        $video = Video::create(new VideoId($videoId), new Owner(self::USER, 'ana@exemplo.com', 'Ana'), new FileName('aula.mp4'), new FileSize(1024), 'video/mp4', $now);
        $video->markUploaded($now);
        $video->markQueued($now);
        $this->app->make(VideoRepositoryInterface::class)->add($video);
        $saga = ProcessingSaga::start(Uuid::uuid7()->toString(), $video->id, Uuid::uuid7()->toString(), $now);
        $saga->uploadValidated($now);
        $saga->quotaReserved($now);
        $saga->processingRequested($now);
        $this->app->make(ProcessingSagaRepositoryInterface::class)->add($saga);
        DB::table('processing_sagas')->where('id', $saga->id)->update(['deadline_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $this->artisan('saga:check-timeouts')->assertSuccessful();

        $check = array_values(array_filter($this->telemetry->events, fn (array $e) => $e['type'] === 'SagaTimeoutCheck'));
        self::assertCount(1, $check);
        self::assertGreaterThanOrEqual(1, $check[0]['attributes']['overdue_count']);
        self::assertGreaterThanOrEqual(1, $check[0]['attributes']['compensated_count']);
        $nossa = array_values(array_filter($this->telemetry->transitions(), fn (array $t) => $t['video_id'] === $videoId));
        self::assertCount(1, $nossa);
        self::assertSame(['PROCESSING_REQUESTED', 'COMPENSATING', 'Timeout', 'PROCESSING_TIMEOUT', 'QUEUED', $saga->id],
            [$nossa[0]['from_state'], $nossa[0]['to_state'], $nossa[0]['trigger'], $nossa[0]['failure_reason'], $nossa[0]['video_status'], $nossa[0]['saga_id']]);
    }
}
