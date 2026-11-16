<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** fiapx_video.processed_messages (spec §9.2): idempotência dos consumidores. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_messages', function (Blueprint $t) {
            $t->char('message_id', 36)->primary();
            $t->string('type', 60);
            $t->timestamp('processed_at')->useCurrent();
            $t->index('processed_at', 'idx_processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_messages');
    }
};
