<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per long job (bulk photos, imports, promotions, exports) so the screen can show
 * live progress and, afterwards, the result — whether the job ran on the queue or inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);                                // photo_batch | import | promotion | export …
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('queued');           // queued | running | done | failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->json('payload')->nullable();                       // what to do (file locations, options)
            $table->json('summary')->nullable();                       // what happened
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status'], 'idx_task_type_status');
            $table->index(['user_id', 'created_at'], 'idx_task_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_tasks');
    }
};
