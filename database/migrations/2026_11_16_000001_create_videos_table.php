<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** fiapx_video.videos (spec §9.2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('user_id', 36)->comment('referência lógica ao fiapx-auth, sem FK');
            $t->string('user_email', 180)->comment('snapshot do token, usado na notificação');
            $t->string('user_name', 120);
            $t->string('original_name', 255);
            $t->string('content_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->string('video_key', 512);
            $t->string('zip_key', 512)->nullable();
            $t->unsignedInteger('frame_count')->nullable();
            $t->string('status', 20)->comment('PENDING_UPLOAD|UPLOADED|QUEUED|PROCESSING|COMPLETED|FAILED|EXPIRED');
            $t->string('failure_code', 40)->nullable();
            $t->string('failure_reason', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index(['user_id', 'created_at'], 'idx_videos_user_created');
            $t->index('status', 'idx_videos_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
