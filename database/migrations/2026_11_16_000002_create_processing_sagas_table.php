<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** fiapx_video.processing_sagas (spec §9.2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_sagas', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('video_id', 36);
            $t->char('correlation_id', 36)->comment('id do evento S3 que iniciou a saga');
            $t->string('state', 30);
            $t->string('current_step', 30);
            $t->timestamp('deadline_at')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->json('history')->comment('[{step, state, at, detail}]');
            $t->unsignedInteger('version')->default(0)->comment('lock otimista');
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->unique('video_id', 'uq_sagas_video');
            $t->index(['state', 'deadline_at'], 'idx_sagas_state_deadline');
            $t->foreign('video_id', 'fk_sagas_video')->references('id')->on('videos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_sagas');
    }
};
