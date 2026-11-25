<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of every promotion: who ran it, when, with what options, and what happened to each student.
 * (The promotion itself only ever adds new enrollment rows; the old ones are never changed.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 10);                                  // auto | manual
            $table->string('from_session', 10);
            $table->string('to_session', 10);
            $table->string('scope', 100)->nullable();                    // a class name, or null for every class
            $table->json('options')->nullable();
            $table->string('status', 12)->default('done');               // done | failed
            $table->unsignedInteger('promoted')->default(0);
            $table->unsignedInteger('repeated')->default(0);
            $table->unsignedInteger('dropped')->default(0);
            $table->unsignedInteger('transferred')->default(0);
            $table->unsignedInteger('graduated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->foreignId('task_id')->nullable()->constrained('background_tasks')->nullOnDelete();
            $table->timestamps();

            $table->index(['from_session', 'to_session'], 'idx_promo_sessions');
        });

        Schema::create('promotion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('promotion_runs')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->foreignId('from_enrollment_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('to_enrollment_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('action', 12);                                // promoted | repeated | dropped | transferred | graduated | skipped
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('profile_id', 'idx_promo_item_profile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotion_runs');
    }
};
