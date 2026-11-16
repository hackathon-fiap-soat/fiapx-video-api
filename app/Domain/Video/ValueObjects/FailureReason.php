<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

/**
 * Motivo da falha. O banco e as mensagens guardam o código; a API e os e-mails mostram o texto
 * (fiapx-docs/03-arquitetura/saga.md, "Motivos legíveis").
 */
enum FailureReason: string
{
    case InvalidUpload = 'INVALID_UPLOAD';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case FfmpegError = 'FFMPEG_ERROR';
    case NoFrames = 'NO_FRAMES';
    case DownloadError = 'DOWNLOAD_ERROR';
    case UploadError = 'UPLOAD_ERROR';
    case ProcessingTimeout = 'PROCESSING_TIMEOUT';

    public function message(): string
    {
        return match ($this) {
            self::InvalidUpload => 'O arquivo enviado não é um vídeo válido ou ultrapassa 500 MB',
            self::QuotaExceeded => 'Você já tem 5 vídeos em processamento. Aguarde a conclusão e envie de novo',
            self::FfmpegError => 'O arquivo de vídeo está corrompido ou em um formato que não conseguimos ler',
            self::NoFrames => 'Não foi possível extrair imagens do vídeo. Ele pode ter menos de 1 segundo',
            self::DownloadError, self::UploadError => 'Tivemos um problema temporário ao processar o vídeo. Tente enviar de novo',
            self::ProcessingTimeout => 'O processamento demorou mais do que o esperado. Tente enviar de novo',
        };
    }
}
