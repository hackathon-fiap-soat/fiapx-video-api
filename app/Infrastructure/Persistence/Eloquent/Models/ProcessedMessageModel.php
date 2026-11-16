<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/** Tabela processed_messages. Datas gravadas explicitamente pelo repositório (UTC). */
final class ProcessedMessageModel extends Model
{
    protected $table = 'processed_messages';

    protected $primaryKey = 'message_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
