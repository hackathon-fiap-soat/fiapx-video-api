<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

/** Conversão de datas entre o domínio e o MySQL (sempre UTC). */
final class Dates
{
    public static function toDb(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function fromDb(mixed $v): \DateTimeImmutable
    {
        if ($v instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($v)->setTimezone(new \DateTimeZone('UTC'));
        }

        return new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC'));
    }

    public static function fromDbNullable(mixed $v): ?\DateTimeImmutable
    {
        return $v === null ? null : self::fromDb($v);
    }
}
