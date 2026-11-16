<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Saga\UseCases\PruneMessagingUseCase;
use Illuminate\Console\Command;

final class MessagingPruneCommand extends Command
{
    protected $signature = 'messaging:prune';

    protected $description = 'Apaga a outbox publicada e processed_messages com mais de 7 dias';

    public function handle(PruneMessagingUseCase $useCase): int
    {
        $r = $useCase->execute();
        $this->info("outbox: {$r['outbox']}, processed_messages: {$r['processed_messages']}.");

        return self::SUCCESS;
    }
}
