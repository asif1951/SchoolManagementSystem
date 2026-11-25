<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded import file: what was found when it was checked (the preview), and what happened
 * when it was confirmed. Nothing is saved to the students until the user confirms the preview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('path', 255)->nullable();                    // the stored upload, removed when the import ends
            $table->string('session', 10);                              // the session used for rows that do not name one
            $table->string('status', 12)->default('previewed');         // previewed | running | done | failed | cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->json('invalid')->nullable();                        // [{row, name, reason}] found by the check
            $table->json('summary')->nullable();                        // created / failed counts after confirming
            $table->foreignId('task_id')->nullable()->constrained('background_tasks')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'idx_import_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
    }
};
