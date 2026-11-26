<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teaching Plan module.
 *
 *  syllabus_chapters/topics   defined once per class level, subject and session; plans pick from it
 *  teaching_plans             the Yearly Plan of one teacher for one subject of one class section (the one source of truth),
 *                             with cached progress figures so dashboards are plain indexed reads
 *  teaching_plan_items        one chapter of a plan: dates and planned periods (monthly / weekly views are derived from these)
 *  teaching_logs              what was actually taught, one row per class period
 *  teaching_reviews           comments of the Head Teacher on a plan
 *  lesson_plans               optional, reusable
 *  teaching_holidays          days on which nothing is taught (used by auto-schedule and "expected by today")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_settings', function (Blueprint $table) {
            $table->id();
            $table->json('values');
            $table->timestamps();
        });

        Schema::create('teaching_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('title', 120);
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('syllabus_chapters', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 10);
            $table->string('class_name', 100);
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('name', 200);
            $table->unsignedSmallInteger('periods')->default(0);       // suggested periods, 0 = not given
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();                                     // a chapter used by a plan or a log stays readable
            $table->index(['academic_year', 'class_name', 'subject_id', 'position'], 'idx_syllabus_set');
        });

        Schema::create('syllabus_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained('syllabus_chapters')->cascadeOnDelete();
            $table->string('name', 200);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['chapter_id', 'position']);
        });

        Schema::create('teaching_plans', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 10);
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('draft');            // draft | submitted | approved | returned
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // cached figures, refreshed when the plan or its logs change (and once a day for "expected by today")
            $table->unsignedSmallInteger('chapters')->default(0);
            $table->unsignedSmallInteger('delayed')->default(0);
            $table->unsignedInteger('planned_periods')->default(0);
            $table->decimal('done_pct', 5, 2)->default(0);
            $table->decimal('expected_pct', 5, 2)->default(0);
            $table->decimal('gap', 6, 2)->default(0);                  // done - expected
            $table->string('health', 8)->default('none');              // green | amber | red | none (nothing dated yet)
            $table->date('projected_finish')->nullable();
            $table->date('last_log_on')->nullable();
            $table->date('summary_on')->nullable();                    // the day the figures were worked out
            $table->timestamps();

            $table->unique(['class_id', 'subject_id', 'teacher_id'], 'uniq_teaching_plan');
            $table->index(['academic_year', 'health', 'gap'], 'idx_plan_health');
            $table->index(['academic_year', 'gap'], 'idx_plan_gap');
            $table->index(['teacher_id', 'academic_year'], 'idx_plan_teacher');
            $table->index(['academic_year', 'status'], 'idx_plan_status');
            $table->index(['academic_year', 'subject_id'], 'idx_plan_subject');
            $table->index(['summary_on', 'academic_year'], 'idx_plan_stale');
        });

        Schema::create('teaching_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('teaching_plans')->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained('syllabus_chapters')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('planned_periods')->default(1);
            $table->decimal('progress', 5, 2)->default(0);             // from the daily log
            $table->date('completed_on')->nullable();                  // set when marked complete by hand
            $table->timestamps();

            $table->unique(['plan_id', 'chapter_id']);
            $table->index(['plan_id', 'position']);
            $table->index(['ends_on', 'progress'], 'idx_item_delayed');
            $table->index(['starts_on', 'ends_on'], 'idx_item_range');
        });

        Schema::create('teaching_logs', function (Blueprint $table) {
            $table->id();
            $table->date('log_date');
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();        // whose class it is
            $table->foreignId('substitute_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('period_id')->nullable();       // routine period; NULL for an extra class
            $table->string('kind', 8)->default('regular');             // regular | extra
            $table->foreignId('plan_id')->nullable()->constrained('teaching_plans')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('teaching_plan_items')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('syllabus_topics')->nullOnDelete();
            $table->boolean('held')->default(true);
            $table->string('not_held_reason', 120)->nullable();
            $table->unsignedTinyInteger('coverage')->default(0);       // 0 not taken … 100 completed
            $table->string('understanding', 8)->nullable();            // good | average | revision
            $table->boolean('homework')->default(false);
            $table->string('next_plan', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['log_date', 'class_id', 'period_id'], 'uniq_log_slot');
            $table->index(['teacher_id', 'log_date'], 'idx_log_teacher');
            $table->index(['log_date', 'teacher_id'], 'idx_log_date');
            $table->index(['plan_id', 'item_id', 'topic_id', 'coverage'], 'idx_log_progress');
            $table->index(['class_id', 'subject_id', 'log_date'], 'idx_log_class');
        });

        Schema::create('teaching_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('teaching_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10)->default('comment');            // comment | approved | returned
            $table->string('body', 1000);
            $table->timestamps();
            $table->index(['plan_id', 'created_at']);
        });

        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('syllabus_chapters')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('syllabus_topics')->nullOnDelete();
            $table->foreignId('log_id')->nullable()->constrained('teaching_logs')->nullOnDelete();
            $table->string('title', 200);
            $table->date('lesson_date')->nullable();
            $table->unsignedSmallInteger('duration')->nullable();      // minutes
            $table->text('objectives')->nullable();
            $table->text('outcomes')->nullable();
            $table->text('method')->nullable();
            $table->text('materials')->nullable();
            $table->text('assessment')->nullable();
            $table->text('homework')->nullable();
            $table->boolean('is_template')->default(false);
            $table->timestamps();
            $table->index(['owner_id', 'is_template', 'lesson_date'], 'idx_lesson_owner');
            $table->index(['class_id', 'subject_id', 'lesson_date'], 'idx_lesson_class');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notifications_unread');
        });

        // permissions given to one person on top of their role
        Schema::table('users', function (Blueprint $table) {
            $table->json('extra_abilities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('extra_abilities');
        });

        foreach (['notifications', 'lesson_plans', 'teaching_reviews', 'teaching_logs', 'teaching_plan_items', 'teaching_plans', 'syllabus_topics', 'syllabus_chapters', 'teaching_holidays', 'teaching_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
