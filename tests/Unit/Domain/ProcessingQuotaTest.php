<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Quota\Entities\ProcessingQuota;
use App\Domain\Quota\Exceptions\QuotaExceeded;
use PHPUnit\Framework\TestCase;

final class ProcessingQuotaTest extends TestCase
{
    public function test_reserva_ate_o_limite_e_libera(): void
    {
        $q = ProcessingQuota::forNewUser('0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6c');
        for ($i = 0; $i < 5; $i++) {
            $q->reserve();
        }
        self::assertSame(5, $q->inProgress());
        try {
            $q->reserve();
            self::fail('deveria exceder');
        } catch (QuotaExceeded) {
        }
        $q->release();
        self::assertSame(4, $q->inProgress());
    }

    public function test_liberar_nunca_fica_negativo(): void
    {
        $q = ProcessingQuota::restore('0192f1c4-6a00-7b00-8c00-1d2e3f4a5b6c', 0, 5);
        $q->release();
        self::assertSame(0, $q->inProgress());
        self::assertSame(5, $q->maxParallel);
    }
}
