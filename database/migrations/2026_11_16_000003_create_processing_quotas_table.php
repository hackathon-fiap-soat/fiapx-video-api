<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** fiapx_video.processing_quotas (spec §9.2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_quotas', function (Blueprint $t) {
            $t->char('user_id', 36)->primary();
            $t->unsignedInteger('in_progress')->default(0);
            $t->unsignedInteger('max_parallel')->default(5);
            $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_quotas');
    }
};
