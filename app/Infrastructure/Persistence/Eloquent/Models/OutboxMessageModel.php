<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/** Tabela outbox_messages. Datas gravadas explicitamente pelo repositório (UTC). */
final class OutboxMessageModel extends Model
{
    protected $table = 'outbox_messages';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
