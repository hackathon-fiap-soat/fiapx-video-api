<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

/** Nome original do arquivo (até 255 caracteres, com extensão aceita). */
final readonly class FileName
{
    public string $value;

    public VideoFormat $format;

    public function __construct(string $value)
    {
        $value = trim(str_replace(["\0", '/', '\\'], '', $value));
        if ($value === '' || mb_strlen($value) > 255) {
            throw new \InvalidArgumentException('Informe o nome do arquivo (até 255 caracteres).');
        }
        $this->format = VideoFormat::fromFileName($value);
        $this->value = $value;
    }
}
