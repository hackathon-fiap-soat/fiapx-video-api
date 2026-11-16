<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** fiapx_video.outbox_messages (spec §9.2): transactional outbox. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->char('id', 36)->primary()->comment('= message_id');
            $t->string('destination', 60)->comment('processing-commands | notification-commands');
            $t->string('type', 60);
            $t->json('payload')->comment('envelope completo');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('published_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['published_at', 'created_at'], 'idx_outbox_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
