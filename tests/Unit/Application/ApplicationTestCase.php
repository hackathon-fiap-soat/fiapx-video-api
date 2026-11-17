<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutgoingMessage;
use App\Application\Shared\Ports\CommandBus;
use App\Application\Shared\Ports\MessageDeduplicator;
use App\Application\Shared\Ports\TransactionManager;
use App\Application\Shared\SagaSettings;
use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Shared\Interfaces\IdGenerator;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\ValueObjects\FileName;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\VideoId;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTelemetry;

abstract class ApplicationTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected const VIDEO = '0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f';

    protected const USER = '0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6c';

    protected const SAGA = '0192f1c4-0000-7000-8000-000000000001';

    protected const EVENT = '0192f1c4-0000-7000-8000-0000000000aa';

    protected \DateTimeImmutable $now;

    protected Clock $clock;

    protected IdGenerator $ids;

    protected SagaSettings $settings;

    /** @var list<OutgoingMessage> */
    protected array $dispatched = [];

    protected CommandBus&MockInterface $commands;

    protected TransactionManager&MockInterface $transactions;

    protected MessageDeduplicator&MockInterface $deduplicator;

    protected FakeTelemetry $telemetry;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-16T12:00:00Z');
        $this->clock = new class($this->now) implements Clock
        {
            public function __construct(public \DateTimeImmutable $at) {}

            public function now(): \DateTimeImmutable
            {
                return $this->at;
            }
        };
        $this->ids = new class implements IdGenerator
        {
            private int $n = 0;

            public function next(): string
            {
                return sprintf('0192f1c4-1111-7000-8000-%012d', ++$this->n);
            }
        };
        $this->settings = new SagaSettings(bucket: 'fiapx-local-videos');
        $this->commands = Mockery::mock(CommandBus::class);
        $this->commands->allows('dispatch')->andReturnUsing(function (OutgoingMessage $m): void {
            $this->dispatched[] = $m;
        });
        $this->transactions = Mockery::mock(TransactionManager::class);
        $this->transactions->allows('run')->andReturnUsing(fn (callable $fn) => $fn());
        $this->deduplicator = Mockery::mock(MessageDeduplicator::class);
        $this->telemetry = new FakeTelemetry;
    }

    protected function messages(): MessageFactory
    {
        return new MessageFactory($this->ids, $this->clock, $this->settings);
    }

    protected function video(string $name = 'aula.mp4'): Video
    {
        return Video::create(new VideoId(self::VIDEO), new Owner(self::USER, 'ana@exemplo.com', 'Ana'), new FileName($name), new FileSize(1000), 'video/mp4', $this->now);
    }

    /** Vídeo e saga já em PROCESSING_REQUESTED (cota reservada). */
    protected function emProcessamento(): array
    {
        $video = $this->video();
        $video->markUploaded($this->now);
        $video->markQueued($this->now);
        $saga = ProcessingSaga::start(self::SAGA, $video->id, self::EVENT, $this->now);
        $saga->uploadValidated($this->now);
        $saga->quotaReserved($this->now);
        $saga->processingRequested($this->now);

        return [$video, $saga];
    }

    /** @return list<string> */
    protected function tiposDespachados(): array
    {
        return array_map(fn (OutgoingMessage $m) => $m->type().':'.($m->envelope['payload']['template'] ?? $m->destination->value), $this->dispatched);
    }
}
