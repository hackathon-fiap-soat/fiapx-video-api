<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

/** As 7 extensões aceitas (spec §6.1, ValidateUpload). */
enum VideoFormat: string
{
    case Mp4 = 'mp4';
    case Avi = 'avi';
    case Mov = 'mov';
    case Mkv = 'mkv';
    case Wmv = 'wmv';
    case Flv = 'flv';
    case Webm = 'webm';

    public static function fromFileName(string $fileName): self
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $format = self::tryFrom($ext);
        if ($format === null) {
            throw new \InvalidArgumentException('O formato do arquivo não é aceito. Use mp4, avi, mov, mkv, wmv, flv ou webm.');
        }

        return $format;
    }

    /** MIME padrão, usado quando o navegador não informa o tipo do arquivo. */
    public function defaultMimeType(): string
    {
        return match ($this) {
            self::Mp4 => 'video/mp4',
            self::Avi => 'video/x-msvideo',
            self::Mov => 'video/quicktime',
            self::Mkv => 'video/x-matroska',
            self::Wmv => 'video/x-ms-wmv',
            self::Flv => 'video/x-flv',
            self::Webm => 'video/webm',
        };
    }

    /**
     * MIME aceito no ValidateUpload: qualquer video/* ou application/octet-stream (navegadores nem sempre
     * reconhecem mkv, flv e wmv). A extensão já foi conferida.
     */
    public static function isAcceptedMimeType(string $mime): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return str_starts_with($mime, 'video/') || $mime === 'application/octet-stream';
    }
}
