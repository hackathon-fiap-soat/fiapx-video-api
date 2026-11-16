<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/** Tabela processing_quotas. Datas gravadas explicitamente pelo repositório (UTC). */
final class ProcessingQuotaModel extends Model
{
    protected $table = 'processing_quotas';

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
