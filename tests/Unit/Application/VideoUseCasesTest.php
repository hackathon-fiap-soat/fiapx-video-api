<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Shared\Ports\ObjectStorage;
use App\Application\Video\DTOs\DownloadLink;
use App\Application\Video\DTOs\RequestVideoUploadInput;
use App\Application\Video\DTOs\UploadTicket;
use App\Application\Video\UseCases\ExpirePendingUploadsUseCase;
use App\Application\Video\UseCases\GetDownloadLinkUseCase;
use App\Application\Video\UseCases\ListUserVideosUseCase;
use App\Application\Video\UseCases\RequestVideoUploadUseCase;
use App\Application\Video\UseCases\ShowVideoUseCase;
use App\Domain\Shared\Exceptions\ConflictException;
use App\Domain\Shared\Exceptions\NotFoundException;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\Interfaces\VideoRepositoryInterface;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Domain\Video\ValueObjects\VideoStatus;
use Mockery;

final class VideoUseCasesTest extends ApplicationTestCase
{
    public function test_request_upload_cria_pendente_e_pede_post_pre_assinado(): void
    {
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $storage = Mockery::mock(ObjectStorage::class);
        $storage->expects('presignUpload')
            ->withArgs(fn (StorageKey $k, string $type, int $max, int $ttl) => $k->value === 'uploads/0192f1c4-1111-7000-8000-000000000001.mov'
                && $type === 'video/quicktime' && $max === 73400320 && $ttl === 3600)
            ->andReturn(new UploadTicket('http://s3/bucket', ['key' => 'k'], $this->now->modify('+1 hour')));
        $videos->expects('add')->withArgs(fn (Video $v) => $v->status() === VideoStatus::PendingUpload && $v->owner->email === 'ana@exemplo.com');

        $out = (new RequestVideoUploadUseCase($videos, $storage, $this->ids, $this->clock, $this->settings))
            ->execute(new RequestVideoUploadInput(self::USER, 'ANA@exemplo.com', 'Ana', 'Aula.MOV', 73400320, 'video/quicktime'));

        self::assertSame('PENDING_UPLOAD', $out->status);
        self::assertSame('http://s3/bucket', $out->upload->url);
    }

    public function test_request_upload_valida_antes_de_gravar(): void
    {
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $videos->shouldNotReceive('add');
        $this->expectException(\InvalidArgumentException::class);
        (new RequestVideoUploadUseCase($videos, Mockery::mock(ObjectStorage::class), $this->ids, $this->clock, $this->settings))
            ->execute(new RequestVideoUploadInput(self::USER, 'ana@exemplo.com', 'Ana', 'foto.png', 10, 'image/png'));
    }

    public function test_lista_limita_paginacao(): void
    {
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $videos->expects('paginateForUser')->with(self::USER, VideoStatus::Completed, 1, 100)->andReturn(['items' => [$this->video()], 'total' => 1]);
        $page = (new ListUserVideosUseCase($videos))->execute(self::USER, VideoStatus::Completed, 0, 500);
        self::assertSame(1, $page->total);
        self::assertSame(100, $page->perPage);
        self::assertSame('aula.mp4', $page->items[0]->originalName);
    }

    public function test_show_de_outro_usuario_e_404(): void
    {
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $videos->expects('findForUser')->andReturnNull();
        $this->expectException(NotFoundException::class);
        (new ShowVideoUseCase($videos))->execute(self::USER, self::VIDEO);
    }

    public function test_show_com_id_mal_formado_e_404(): void
    {
        $this->expectException(NotFoundException::class);
        (new ShowVideoUseCase(Mockery::mock(VideoRepositoryInterface::class)))->execute(self::USER, 'abc');
    }

    public function test_show_devolve_motivo_legivel(): void
    {
        $video = $this->video();
        $video->markUploaded($this->now);
        $video->fail(FailureReason::QuotaExceeded, $this->now);
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $videos->expects('findForUser')->andReturn($video);
        $view = (new ShowVideoUseCase($videos))->execute(self::USER, self::VIDEO);
        self::assertSame('QUOTA_EXCEEDED', $view->failureCode);
        self::assertStringContainsString('5 vídeos', (string) $view->failureReason);
    }

    public function test_download_so_quando_concluido(): void
    {
        $video = $this->video();
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $videos->allows('findForUser')->andReturn($video);
        $storage = Mockery::mock(ObjectStorage::class);
        $uc = new GetDownloadLinkUseCase($videos, $storage, $this->settings);
        try {
            $uc->execute(self::USER, self::VIDEO);
            self::fail('deveria dar 409');
        } catch (ConflictException $e) {
            self::assertSame('O vídeo ainda não está pronto para download.', $e->getMessage());
        }

        $video->markUploaded($this->now);
        $video->markQueued($this->now);
        $video->complete(StorageKey::framesZip($video->id), 10, $this->now);
        $storage->expects('presignDownload')->withArgs(fn (StorageKey $k, int $ttl) => $k->value === 'results/'.self::VIDEO.'/frames.zip' && $ttl === 900)
            ->andReturn(new DownloadLink('http://s3/zip', $this->now->modify('+15 minutes')));
        self::assertSame('http://s3/zip', $uc->execute(self::USER, self::VIDEO)->url);
    }

    public function test_download_inexistente_e_404(): void
    {
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $videos->expects('findForUser')->andReturnNull();
        $uc = new GetDownloadLinkUseCase($videos, Mockery::mock(ObjectStorage::class), $this->settings);
        $this->expectException(NotFoundException::class);
        $uc->execute(self::USER, self::VIDEO);
    }

    public function test_download_id_mal_formado_e_404(): void
    {
        $this->expectException(NotFoundException::class);
        (new GetDownloadLinkUseCase(Mockery::mock(VideoRepositoryInterface::class), Mockery::mock(ObjectStorage::class), $this->settings))->execute(self::USER, 'x');
    }

    public function test_expira_pendentes_relendo_com_lock(): void
    {
        $pendente = $this->video();
        $jaEnviado = $this->video();
        $jaEnviado->markUploaded($this->now);
        $videos = Mockery::mock(VideoRepositoryInterface::class);
        $videos->expects('pendingUploadCreatedBefore')->withArgs(fn (\DateTimeImmutable $l) => $l == $this->now->modify('-3600 seconds'))->andReturn([$pendente, $pendente]);
        $videos->expects('findForUpdate')->twice()->andReturn($pendente, $jaEnviado);
        $videos->expects('save')->once()->with($pendente);
        $n = (new ExpirePendingUploadsUseCase($videos, $this->transactions, $this->clock, $this->settings))->execute();
        self::assertSame(1, $n);
        self::assertSame(VideoStatus::Expired, $pendente->status());
    }
}
