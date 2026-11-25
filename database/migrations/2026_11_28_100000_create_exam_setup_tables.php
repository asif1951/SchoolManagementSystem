<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam setup — everything the exam controller configures from the dashboard.
 *
 *  exam_component_types → editable list of paper parts: Written, CQ, MCQ, Practical, Viva …
 *  grading_scales(+rows)→ editable grading scale(s); the default is the Bangladesh 5-point scale
 *  result_policies      → ONE settings row per academic year (how the final result is calculated)
 *  exams                → the exams of a year, each with its weight toward the final result
 *  exam_papers          → for an exam + class + subject: which parts exist, full marks, pass marks
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_component_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('grading_scale_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_scale_id')->constrained('grading_scales')->cascadeOnDelete();
            $table->decimal('min_percent', 5, 2);              // lowest percentage that earns this grade
            $table->string('grade', 10);
            $table->decimal('grade_point', 4, 2);
            $table->boolean('is_fail')->default(false);
            $table->timestamps();

            $table->index(['grading_scale_id', 'min_percent']);
        });

        Schema::create('result_policies', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 10)->unique();
            // how the final result is built
            $table->string('calculation_mode', 30)->default('per_subject');      // per_subject | overall_percentage
            // where pass marks are checked
            $table->string('pass_rule_scope', 30)->default('final_only');        // each_exam | final_only
            $table->boolean('require_component_pass')->default(true);            // every part (CQ, MCQ, practical…) must reach its pass marks
            $table->foreignId('grading_scale_id')->nullable()->constrained('grading_scales')->nullOnDelete();
            // optional ("4th") subject
            $table->string('optional_rule', 30)->default('above_threshold');     // none | above_threshold
            $table->decimal('optional_threshold', 4, 2)->default(2.00);
            $table->timestamps();
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 10);
            $table->string('name', 100);
            $table->decimal('weight', 5, 2)->default(0);        // % this exam contributes to the final result
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('draft');     // draft → open → closed → published
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['academic_year', 'name']);
            $table->index(['academic_year', 'status']);
        });

        Schema::create('exam_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('component_type_id')->constrained('exam_component_types')->restrictOnDelete();
            $table->decimal('full_marks', 6, 2);
            $table->decimal('pass_marks', 6, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'class_id', 'subject_id', 'component_type_id'], 'exam_paper_unique');
            $table->index(['exam_id', 'class_id']);
        });

        // ---- required defaults (system data, not demo data) -----------------------------------
        $order = 0;
        foreach ([['Written', 'WRT'], ['CQ', 'CQ'], ['MCQ', 'MCQ'], ['Practical', 'PRC'], ['Viva', 'VIV']] as [$name, $code]) {
            DB::table('exam_component_types')->insert([
                'name' => $name, 'code' => $code, 'sort_order' => ++$order, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $scaleId = DB::table('grading_scales')->insertGetId([
            'name' => 'Bangladesh 5-point scale', 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[80, 'A+', 5.00, false], [70, 'A', 4.00, false], [60, 'A-', 3.50, false], [50, 'B', 3.00, false],
                  [40, 'C', 2.00, false], [33, 'D', 1.00, false], [0, 'F', 0.00, true]] as [$min, $grade, $point, $fail]) {
            DB::table('grading_scale_rows')->insert([
                'grading_scale_id' => $scaleId, 'min_percent' => $min, 'grade' => $grade, 'grade_point' => $point,
                'is_fail' => $fail, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // the person who runs examinations
        // On a brand-new database the earlier roles migration already creates every role listed in
        // config/abilities.php (including this one) — so this must be safe to run either way.
        DB::table('roles')->updateOrInsert(
            ['slug' => 'exam_controller'],
            [
                'name' => 'Exam controller',
                'description' => 'Sets up exams and result rules, oversees marking and publishes results.',
                'abilities' => json_encode(config('abilities.default_roles.exam_controller.abilities', [])),
                'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'exam_controller')->delete();

        Schema::dropIfExists('exam_papers');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('result_policies');
        Schema::dropIfExists('grading_scale_rows');
        Schema::dropIfExists('grading_scales');
        Schema::dropIfExists('exam_component_types');
    }
};
