<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Video\Entities\Video;
use App\Domain\Video\ValueObjects\FailureReason;
use App\Domain\Video\ValueObjects\FileName;
use App\Domain\Video\ValueObjects\FileSize;
use App\Domain\Video\ValueObjects\Owner;
use App\Domain\Video\ValueObjects\StorageKey;
use App\Domain\Video\ValueObjects\VideoFormat;
use App\Domain\Video\ValueObjects\VideoId;
use App\Domain\Video\ValueObjects\VideoStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoTest extends TestCase
{
    private const ID = '0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f';

    private const USER = '0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6c';

    private function video(string $name = 'aula-01.MP4', string $type = 'video/mp4'): Video
    {
        return Video::create(new VideoId(self::ID), new Owner(self::USER, 'Ana@Exemplo.com', 'Ana'), new FileName($name), new FileSize(1000), $type, new \DateTimeImmutable('2026-11-16T12:00:00Z'));
    }

    public function test_cria_pendente_com_chave_de_upload_em_minusculas(): void
    {
        $v = $this->video();
        self::assertSame(VideoStatus::PendingUpload, $v->status());
        self::assertSame('uploads/'.self::ID.'.mp4', $v->videoKey->value);
        self::assertSame('ana@exemplo.com', $v->owner->email);
        self::assertTrue($v->belongsTo(self::USER));
        self::assertFalse($v->belongsTo('0192f1c4-0000-7000-8000-000000000000'));
    }

    public function test_usa_mime_padrao_quando_o_navegador_nao_informa(): void
    {
        self::assertSame('video/x-matroska', $this->video('filme.mkv', '')->contentType);
    }

    public function test_ciclo_de_sucesso(): void
    {
        $v = $this->video();
        $now = new \DateTimeImmutable('2026-11-16T12:01:00Z');
        $v->markUploaded($now);
        $v->markQueued($now);
        $v->markProcessing($now);
        $v->complete(StorageKey::framesZip($v->id), 120, $now);
        self::assertSame(VideoStatus::Completed, $v->status());
        self::assertSame(120, $v->frameCount());
        self::assertSame('results/'.self::ID.'/frames.zip', $v->zipKey()?->value);
        self::assertEquals($now, $v->updatedAt());
    }

    public function test_aceita_conclusao_direto_da_fila(): void
    {
        $v = $this->video();
        $now = new \DateTimeImmutable;
        $v->markUploaded($now);
        $v->markQueued($now);
        $v->complete(StorageKey::framesZip($v->id), 1, $now);
        self::assertSame(VideoStatus::Completed, $v->status());
    }

    public function test_falha_guarda_o_motivo(): void
    {
        $v = $this->video();
        $v->markUploaded(new \DateTimeImmutable);
        $v->fail(FailureReason::InvalidUpload, new \DateTimeImmutable);
        self::assertSame(VideoStatus::Failed, $v->status());
        self::assertSame(FailureReason::InvalidUpload, $v->failure());
    }

    public function test_expira_pendente(): void
    {
        $v = $this->video();
        $v->expire(new \DateTimeImmutable);
        self::assertSame(VideoStatus::Expired, $v->status());
    }

    public function test_recusa_transicao_invalida(): void
    {
        $v = $this->video();
        $this->expectException(\DomainException::class);
        $v->markProcessing(new \DateTimeImmutable);
    }

    public function test_recusa_frames_zero(): void
    {
        $v = $this->video();
        $this->expectException(\InvalidArgumentException::class);
        $v->complete(StorageKey::framesZip($v->id), 0, new \DateTimeImmutable);
    }

    public function test_recusa_content_type_longo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->video('a.mp4', str_repeat('x', 101));
    }

    public function test_status_finais(): void
    {
        self::assertTrue(VideoStatus::Completed->isFinal());
        self::assertTrue(VideoStatus::Expired->isFinal());
        self::assertFalse(VideoStatus::Queued->isFinal());
        self::assertFalse(VideoStatus::Failed->canTransitionTo(VideoStatus::Completed));
    }

    /** @return iterable<array{string}> */
    public static function nomesInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'sem extensão' => ['video'];
        yield 'extensão não aceita' => ['foto.png'];
        yield 'longo demais' => [str_repeat('a', 252).'.mp4'];
    }

    #[DataProvider('nomesInvalidos')]
    public function test_nome_invalido(string $nome): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileName($nome);
    }

    public function test_nome_remove_barras(): void
    {
        self::assertSame('..aula.mp4', (new FileName('../aula.mp4'))->value);
    }

    public function test_tamanho(): void
    {
        self::assertSame(FileSize::MAX_BYTES, (new FileSize(524_288_000))->bytes);
        self::assertFalse(FileSize::fits(524_288_001));
        self::assertTrue(FileSize::fits(1));
        $this->expectException(\InvalidArgumentException::class);
        new FileSize(0);
    }

    public function test_formatos_e_mime(): void
    {
        foreach (VideoFormat::cases() as $f) {
            self::assertStringStartsWith('video/', $f->defaultMimeType());
        }
        self::assertTrue(VideoFormat::isAcceptedMimeType('video/mp4; codecs=avc1'));
        self::assertTrue(VideoFormat::isAcceptedMimeType('application/octet-stream'));
        self::assertFalse(VideoFormat::isAcceptedMimeType('image/png'));
    }

    public function test_chaves_do_s3(): void
    {
        $id = new VideoId(self::ID);
        self::assertSame('results/'.self::ID.'/', StorageKey::resultsPrefix($id)->value);
        self::assertTrue($id->equals(StorageKey::videoIdFromUploadKey('uploads/'.self::ID.'.webm')));
        self::assertNull(StorageKey::videoIdFromUploadKey('uploads/outro.mp4'));
        self::assertNull(StorageKey::videoIdFromUploadKey('results/'.self::ID.'/frames.zip'));
        self::assertSame(VideoFormat::Mov, StorageKey::fromString('uploads/x.MOV')->format());
        self::assertNull(StorageKey::fromString('uploads/x.exe')->format());
        self::assertSame('uploads/x.mp4', (string) StorageKey::fromString('uploads/x.mp4'));
    }

    public function test_motivos_legiveis(): void
    {
        foreach (FailureReason::cases() as $r) {
            self::assertNotSame('', $r->message());
        }
        self::assertStringContainsString('corrompido', FailureReason::FfmpegError->message());
    }

    public function test_dono_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Owner(self::USER, 'nao-e-email', 'Ana');
    }

    public function test_dono_sem_nome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Owner(self::USER, 'ana@exemplo.com', ' ');
    }

    public function test_id_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VideoId('abc');
    }
}
