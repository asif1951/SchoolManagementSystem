<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Study groups (Science / Commerce / Arts / anything the school defines) and the rules
 * that decide which subjects a student takes.
 *
 *  study_groups              → admin-managed list of groups (no names are hard-coded)
 *  classes.study_group_id    → a class can be group-wise ("Class 9 Science A"), or NULL = mixed
 *  students.study_group_id   → the group a student studies in
 *  class_subject_groups      → limits a subject of a class to certain groups (none = everyone)
 *  curriculum_subjects       → templates: "Class 9 gets these subjects; Science adds these…"
 *  student_optional_subjects → the optional subject(s) a student chose
 *
 * `class_subjects.is_optional` (from the subject module) marks a subject as optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('study_group_id')->nullable()->after('section')
                ->constrained('study_groups')->restrictOnDelete();

            // "Class 9 – Science – A" and "Class 9 – Arts – A" must be able to coexist.
            $table->dropUnique('classes_name_section_year_unique');
            $table->unique(['class_name', 'section', 'academic_year', 'study_group_id'], 'classes_name_section_year_group_unique');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('study_group_id')->nullable()->after('class_id')
                ->constrained('study_groups')->restrictOnDelete();
        });

        Schema::create('class_subject_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_id')->constrained('class_subjects')->cascadeOnDelete();
            $table->foreignId('study_group_id')->constrained('study_groups')->restrictOnDelete();
            $table->unique(['class_subject_id', 'study_group_id'], 'class_subject_group_unique');
        });

        Schema::create('curriculum_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('class_name', 100);                  // the class level, e.g. "Class 9"
            $table->foreignId('study_group_id')->nullable()->constrained('study_groups')->cascadeOnDelete(); // NULL = every group
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->boolean('is_optional')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['class_name', 'study_group_id', 'subject_id'], 'curriculum_unique');
            $table->index('class_name');
        });

        Schema::create('student_optional_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_optional_subjects');
        Schema::dropIfExists('curriculum_subjects');
        Schema::dropIfExists('class_subject_groups');

        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['study_group_id']);
            $table->dropColumn('study_group_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique('classes_name_section_year_group_unique');
            $table->dropForeign(['study_group_id']);
            $table->dropColumn('study_group_id');
            $table->unique(['class_name', 'section', 'academic_year'], 'classes_name_section_year_unique');
        });

        Schema::dropIfExists('study_groups');
    }
};
