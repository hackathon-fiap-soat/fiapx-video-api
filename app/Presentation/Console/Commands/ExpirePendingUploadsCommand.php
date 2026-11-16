<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Video\UseCases\ExpirePendingUploadsUseCase;
use Illuminate\Console\Command;

final class ExpirePendingUploadsCommand extends Command
{
    protected $signature = 'videos:expire-pending';

    protected $description = 'Marca como EXPIRED os vídeos em PENDING_UPLOAD há mais de 1 hora';

    public function handle(ExpirePendingUploadsUseCase $useCase): int
    {
        $this->info($useCase->execute().' vídeo(s) expirado(s).');

        return self::SUCCESS;
    }
}
