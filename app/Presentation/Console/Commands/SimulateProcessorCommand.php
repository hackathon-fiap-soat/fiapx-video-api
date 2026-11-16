<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Domain\Shared\Interfaces\IdGenerator;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Messaging\MessageQueue;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;

/**
 * Processor simulado (só ambiente local), até o fiapx-video-processor existir (Etapa 5). Consome
 * processing-commands e responde na saga-replies.fifo com os mesmos contratos do processor real:
 *
 * - ProcessVideo → ProcessingStarted, grava um zip falso em results/{id}/frames.zip e VideoProcessed.
 *   Um arquivo com menos de 8 bytes simula vídeo corrompido: responde VideoProcessingFailed (FFMPEG_ERROR).
 * - CleanupArtifacts → apaga o prefixo e responde ArtifactsCleaned.
 */
final class SimulateProcessorCommand extends Command
{
    protected $signature = 'processor:simulate {--once : trata o que houver na fila e sai} {--frames=3 : frames do zip falso}';

    protected $description = '[local] Simula o fiapx-video-processor para testar a SAGA de ponta a ponta';

    private bool $running = true;

    public function handle(MessageQueue $queue, SqsClient $sqs, S3Client $s3, ParameterResolver $parameters, IdGenerator $ids): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('processor:simulate só roda com APP_ENV=local.');

            return self::FAILURE;
        }
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });
        $commands = $parameters->get('processing_commands_queue_url');
        $replies = $parameters->get('saga_replies_queue_url');

        $reply = function (array $command, string $type, array $payload) use ($sqs, $replies, $ids): void {
            $id = $ids->next();
            $sqs->sendMessage([
                'QueueUrl' => $replies,
                'MessageGroupId' => $command['saga_id'],
                'MessageDeduplicationId' => $id,
                'MessageBody' => json_encode([
                    'message_id' => $id, 'type' => $type, 'version' => 1,
                    'saga_id' => $command['saga_id'], 'video_id' => $command['video_id'], 'correlation_id' => $command['correlation_id'],
                    'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'payload' => $payload,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
            $this->line("→ {$type} ({$command['video_id']})");
        };

        do {
            $messages = $queue->receive($commands, $this->option('once') ? 1 : 10);
            foreach ($messages as $message) {
                $command = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);
                $p = $command['payload'];
                if ($command['type'] === 'ProcessVideo') {
                    $reply($command, 'ProcessingStarted', ['started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'worker_id' => 'simulador-'.gethostname()]);
                    $video = $s3->headObject(['Bucket' => $p['bucket'], 'Key' => $p['video_key']]);
                    if ((int) $video['ContentLength'] < 8) {
                        $reply($command, 'VideoProcessingFailed', ['error_code' => 'FFMPEG_ERROR', 'error_message' => 'simulado: arquivo pequeno demais', 'attempt' => 1]);
                    } else {
                        $zipKey = $p['output_prefix'].'frames.zip';
                        $zip = $this->fakeZip((int) $this->option('frames'));
                        $s3->putObject(['Bucket' => $p['bucket'], 'Key' => $zipKey, 'Body' => $zip, 'ContentType' => 'application/zip']);
                        $reply($command, 'VideoProcessed', ['zip_key' => $zipKey, 'frame_count' => (int) $this->option('frames'), 'zip_size_bytes' => strlen($zip), 'duration_ms' => 10]);
                    }
                } elseif ($command['type'] === 'CleanupArtifacts') {
                    $objects = $s3->listObjectsV2(['Bucket' => $p['bucket'], 'Prefix' => $p['prefix']])['Contents'] ?? [];
                    foreach ($objects as $o) {
                        $s3->deleteObject(['Bucket' => $p['bucket'], 'Key' => $o['Key']]);
                    }
                    $reply($command, 'ArtifactsCleaned', ['deleted_objects' => count($objects)]);
                }
                $queue->delete($commands, $message);
            }
        } while ($this->running && ! ($this->option('once') && $messages === []));

        return self::SUCCESS;
    }

    /** Zip com N imagens PNG de 1x1 pixel, só para o download funcionar. */
    private function fakeZip(int $frames): string
    {
        $path = tempnam(sys_get_temp_dir(), 'frames');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        for ($i = 1; $i <= $frames; $i++) {
            $zip->addFromString(sprintf('frame_%04d.png', $i), $png);
        }
        $zip->close();
        $data = (string) file_get_contents($path);
        unlink($path);

        return $data;
    }
}
