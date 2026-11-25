<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student keeps the same `student_id` for life; each academic year is its own row
 * (new class + new roll). `promoted_from_id` links a year's row to the previous
 * year's row, so a student's journey can be followed and a promotion can be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('promoted_from_id')
                ->nullable()
                ->after('class_id')
                ->constrained('students')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['promoted_from_id']);
            $table->dropColumn('promoted_from_id');
        });
    }
};
