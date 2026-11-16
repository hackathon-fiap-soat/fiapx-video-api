<?php

declare(strict_types=1);

namespace App\Domain\Saga\ValueObjects;

/** Passos da saga e passos de compensação (spec §6.1). */
enum SagaStep: string
{
    case ValidateUpload = 'ValidateUpload';
    case ReserveQuota = 'ReserveQuota';
    case ProcessVideo = 'ProcessVideo';
    case CompleteVideo = 'CompleteVideo';
    case NotifyUser = 'NotifyUser';
    case DeleteUploadedObject = 'DeleteUploadedObject';
    case CleanupArtifacts = 'CleanupArtifacts';
    case ReleaseQuota = 'ReleaseQuota';
    case Done = 'Done';
}
