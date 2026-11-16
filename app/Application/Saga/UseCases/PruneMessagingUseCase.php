<?php

declare(strict_types=1);

namespace App\Application\Saga\UseCases;

use App\Application\Shared\Ports\MessageDeduplicator;
use App\Application\Shared\Ports\OutboxHousekeeping;
use App\Application\Shared\SagaSettings;
use App\Domain\Shared\Interfaces\Clock;

/**
 * messaging:prune (diário): apaga a outbox já publicada e processed_messages com mais de 7 dias. O SQS retém
 * mensagens por no máximo 4 dias, então uma duplicata não chega depois disso.
 */
final readonly class PruneMessagingUseCase
{
    public function __construct(
        private OutboxHousekeeping $outbox,
        private MessageDeduplicator $deduplicator,
        private Clock $clock,
        private SagaSettings $settings,
    ) {}

    /** @return array{outbox: int, processed_messages: int} */
    public function execute(): array
    {
        $limit = $this->clock->now()->modify("-{$this->settings->retentionDays} days");

        return [
            'outbox' => $this->outbox->prunePublishedBefore($limit),
            'processed_messages' => $this->deduplicator->pruneOlderThan($limit),
        ];
    }
}
