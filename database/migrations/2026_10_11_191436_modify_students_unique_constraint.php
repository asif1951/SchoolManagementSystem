<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // ১. পুরোনো single-column unique constraint drop
            $table->dropUnique('students_student_id_unique');

            // ২. নতুন composite unique (student_id + academic_year)
            $table->unique(
                ['student_id', 'academic_year'],
                'students_student_id_year_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_student_id_year_unique');
            $table->unique('student_id', 'students_student_id_unique');
        });
    }
};