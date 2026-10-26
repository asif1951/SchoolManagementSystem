<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // ⚡ List queries fast করবে (year + class)
            $table->index(['academic_year', 'class'], 'idx_students_year_class');

            // ⚡ Year + class + section
            $table->index(['academic_year', 'class', 'section'], 'idx_students_year_class_section');

            // ⚡ Shift duplicate check
            $table->index(['student_id', 'academic_year'], 'idx_students_sid_year');

            // ⚡ Filter by status
            $table->index('status', 'idx_students_status');

            // ⚡ Search by roll
            $table->index('roll_number', 'idx_students_roll');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('idx_students_year_class');
            $table->dropIndex('idx_students_year_class_section');
            $table->dropIndex('idx_students_sid_year');
            $table->dropIndex('idx_students_status');
            $table->dropIndex('idx_students_roll');
        });
    }
};