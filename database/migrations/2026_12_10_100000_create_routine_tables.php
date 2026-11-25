<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The class routine (timetable).
 *
 *  routine_periods      the bell schedule: periods and breaks with their times, one set per shift (or one for all)
 *  routine_settings     which days of the week the school runs
 *  class_routines       one routine per class row (class + section + shift + medium + group); a draft being edited,
 *                       and, once published, a frozen copy of what was published (published_slots)
 *  class_routine_slots  one cell of the draft: a day, a period, a subject (or a label such as "Games") and a teacher
 *
 * Days are numbered like PHP's date('w'): 0 = Sunday … 6 = Saturday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->cascadeOnDelete();   // NULL = every shift without a set of its own
            $table->string('name', 40);
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_break')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['shift_id', 'sort_order'], 'idx_routine_period_order');
        });

        Schema::create('routine_settings', function (Blueprint $table) {
            $table->id();
            $table->json('working_days');
            $table->timestamps();
        });

        DB::table('routine_settings')->insert(['working_days' => json_encode([0, 1, 2, 3, 4]), 'created_at' => now(), 'updated_at' => now()]);   // Sunday to Thursday

        Schema::create('class_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->unique()->constrained('classes')->cascadeOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('published_slots')->nullable();           // what was published: [{day, period_id, subject_id, label, teacher_id}]
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('class_routine_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_routine_id')->constrained('class_routines')->cascadeOnDelete();
            $table->unsignedTinyInteger('day');
            $table->foreignId('period_id')->constrained('routine_periods')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('label', 60)->nullable();               // a period that is not a subject: Games, Library, Assembly
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['class_routine_id', 'day', 'period_id'], 'uniq_routine_cell');
            $table->index(['teacher_id', 'day', 'period_id'], 'idx_routine_teacher_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_routine_slots');
        Schema::dropIfExists('class_routines');
        Schema::dropIfExists('routine_settings');
        Schema::dropIfExists('routine_periods');
    }
};
