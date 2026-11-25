<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every class can have its own periods and breaks, and a weekly plan for the automatic routine.
 *
 *  routine_periods.class_id   a period that belongs to ONE class (its own bell schedule). Rows without a class are the
 *                             shift / common sets, as before.
 *  routine_plans              how many periods a week each subject of a class gets, and the most in one day
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_periods', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('shift_id')->constrained('classes')->cascadeOnDelete();
            $table->index(['class_id', 'sort_order'], 'idx_routine_period_class');
        });

        Schema::create('routine_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('subject', 100);
            $table->unsignedTinyInteger('periods_per_week')->default(0);
            $table->unsignedTinyInteger('max_per_day')->default(1);
            $table->timestamps();

            $table->unique(['class_id', 'subject'], 'uniq_routine_plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_plans');

        Schema::table('routine_periods', function (Blueprint $table) {
            $table->dropIndex('idx_routine_period_class');
            $table->dropConstrainedForeignId('class_id');
        });
    }
};
