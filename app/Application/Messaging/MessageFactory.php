<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Shared\SagaSettings;
use App\Domain\Saga\Entities\ProcessingSaga;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Shared\Interfaces\IdGenerator;
use App\Domain\Video\Entities\Video;
use App\Domain\Video\ValueObjects\StorageKey;

/**
 * Monta os comandos da saga no formato dos contratos v1 (produtor estrito: só os campos do schema).
 * O correlation_id é sempre o da saga.
 */
final readonly class MessageFactory
{
    public function __construct(private IdGenerator $ids, private Clock $clock, private SagaSettings $settings) {}

    public function processVideo(ProcessingSaga $saga, Video $video): OutgoingMessage
    {
        return new OutgoingMessage(Destination::ProcessingCommands, $this->envelope('ProcessVideo', $saga, [
            'bucket' => $this->settings->bucket,
            'video_key' => $video->videoKey->value,
            'fps' => 1,
            'output_prefix' => StorageKey::resultsPrefix($video->id)->value,
        ]));
    }

    public function cleanupArtifacts(ProcessingSaga $saga): OutgoingMessage
    {
        return new OutgoingMessage(Destination::ProcessingCommands, $this->envelope('CleanupArtifacts', $saga, [
            'bucket' => $this->settings->bucket,
            'prefix' => StorageKey::resultsPrefix($saga->videoId)->value,
        ]));
    }

    public function videoFailed(ProcessingSaga $saga, Video $video): OutgoingMessage
    {
        $payload = $this->recipient($video) + ['template' => 'VIDEO_FAILED'];
        if ($video->failure() !== null) {
            $payload['reason'] = $video->failure()->value;
        }

        return new OutgoingMessage(Destination::NotificationCommands, $this->envelope('SendNotification', $saga, $payload));
    }

    public function videoCompleted(ProcessingSaga $saga, Video $video): OutgoingMessage
    {
        return new OutgoingMessage(Destination::NotificationCommands, $this->envelope('SendNotification', $saga,
            $this->recipient($video) + ['template' => 'VIDEO_COMPLETED']));
    }

    /** @return array<string, string> */
    private function recipient(Video $video): array
    {
        return [
            'recipient_email' => $video->owner->email,
            'recipient_name' => $video->owner->name,
            'video_name' => mb_substr($video->originalName, 0, 255),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(string $type, ProcessingSaga $saga, array $payload): array
    {
        return [
            'message_id' => $this->ids->next(),
            'type' => $type,
            'version' => 1,
            'saga_id' => $saga->id,
            'video_id' => $saga->videoId->value,
            'correlation_id' => $saga->correlationId,
            'occurred_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'payload' => $payload,
        ];
    }
}
