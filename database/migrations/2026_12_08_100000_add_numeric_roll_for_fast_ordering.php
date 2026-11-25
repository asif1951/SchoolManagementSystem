<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lists order students by roll number as a number (2 before 10). Sorting 50,000 rows on CAST(roll_number) needs a
 * full sort every time; a generated numeric column with an index (session, class, roll) lets the database read the
 * rows already in order and stop after one page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('students', 'roll_no')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedInteger('roll_no')->virtualAs("IF(roll_number REGEXP '^[0-9]{1,9}$', CAST(roll_number AS UNSIGNED), 0)");
            $table->index(['academic_year', 'class_id', 'roll_no'], 'idx_students_year_class_roll');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('idx_students_year_class_roll');
            $table->dropColumn('roll_no');
        });
    }
};
