<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B of the class_id refactor.
 *
 * `class_id` is now the single source of truth for a student's / attendance row's
 * class. The duplicated `class` + `section` text columns are dropped and `class_id`
 * becomes NOT NULL, so a row can never point at "no class" or at a stale name.
 *
 * Refuses to run while any row is still unlinked, so no link is lost silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unlinkedStudents    = DB::table('students')->whereNull('class_id')->count();
        $unlinkedAttendances = DB::table('attendances')->whereNull('class_id')->count();

        if ($unlinkedStudents || $unlinkedAttendances) {
            throw new RuntimeException(
                "Cannot drop legacy class columns: {$unlinkedStudents} student(s) and "
                . "{$unlinkedAttendances} attendance row(s) have no class_id. "
                . 'Link them to a class (or delete them) and run the migration again.'
            );
        }

        // Indexes that include the old text columns must go before the columns do.
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('idx_students_year_class');
            $table->dropIndex('idx_students_year_class_section');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['class', 'section', 'attendance_date']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['class', 'section']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['class', 'section']);
        });

        DB::statement('ALTER TABLE students    MODIFY class_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE attendances MODIFY class_id BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE students    MODIFY class_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE attendances MODIFY class_id BIGINT UNSIGNED NULL');

        Schema::table('students', function (Blueprint $table) {
            $table->string('class')->nullable()->after('class_id');
            $table->string('section')->nullable()->after('class');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('class')->nullable()->after('class_id');
            $table->string('section')->nullable()->after('class');
        });

        // Restore the text copies from the linked class.
        DB::statement('UPDATE students s JOIN classes c ON c.id = s.class_id SET s.class = c.class_name, s.section = c.section');
        DB::statement('UPDATE attendances a JOIN classes c ON c.id = a.class_id SET a.class = c.class_name, a.section = c.section');

        Schema::table('students', function (Blueprint $table) {
            $table->index(['academic_year', 'class'], 'idx_students_year_class');
            $table->index(['academic_year', 'class', 'section'], 'idx_students_year_class_section');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['class', 'section', 'attendance_date']);
        });
    }
};
