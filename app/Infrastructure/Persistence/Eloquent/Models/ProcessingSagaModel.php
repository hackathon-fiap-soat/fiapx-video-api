<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/** Tabela processing_sagas. Datas gravadas explicitamente pelo repositório (UTC). */
final class ProcessingSagaModel extends Model
{
    protected $table = 'processing_sagas';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
