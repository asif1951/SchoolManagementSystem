<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A of the class_id refactor.
 *
 * Adds an indexed foreign key `class_id` to students and attendances and
 * back-fills it from the legacy (class, section, academic_year) text columns.
 * The text columns are kept for now so existing code keeps working; they are
 * dropped in Phase B once nothing reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('class_id')
                ->nullable()
                ->after('academic_year')
                ->constrained('classes')
                ->restrictOnDelete();

            $table->index(['academic_year', 'class_id', 'status'], 'idx_students_year_classid_status');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('class_id')
                ->nullable()
                ->after('student_id')
                ->constrained('classes')
                ->restrictOnDelete();

            $table->index(['class_id', 'attendance_date'], 'idx_attendance_classid_date');
        });

        // Back-fill is MySQL-only syntax; fresh/test databases have no legacy rows anyway.
        if (DB::getDriverName() === 'mysql') {
                // Match on class name + section (NULL and '' treated alike) + year.
            DB::statement("
                UPDATE students s
                JOIN classes c
                  ON c.class_name = s.class
                 AND COALESCE(c.section, '') = COALESCE(s.section, '')
                 AND c.academic_year = s.academic_year
                SET s.class_id = c.id
            ");

            DB::statement("
                UPDATE attendances a
                JOIN classes c
                  ON c.class_name = a.class
                 AND COALESCE(c.section, '') = COALESCE(a.section, '')
                 AND c.academic_year = a.academic_year
                SET a.class_id = c.id
            ");

        }

        $orphanStudents    = DB::table('students')->whereNull('class_id')->count();
        $orphanAttendances = DB::table('attendances')->whereNull('class_id')->count();

        if ($orphanStudents || $orphanAttendances) {
            fwrite(STDERR, "\n[class_id backfill] unmatched rows left NULL: "
                . "students={$orphanStudents}, attendances={$orphanAttendances}\n");
        }
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropIndex('idx_attendance_classid_date');
            $table->dropColumn('class_id');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropIndex('idx_students_year_classid_status');
            $table->dropColumn('class_id');
        });
    }
};
