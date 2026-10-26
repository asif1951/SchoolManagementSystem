<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->unsignedBigInteger('class_id');
            $table->string('subject');
            $table->string('exam_type')->default('final');
            $table->string('academic_year');
            $table->decimal('marks', 5, 2);
            $table->string('grade', 5);
            $table->decimal('gpa', 3, 2);
            $table->foreignId('entered_by')->nullable()->constrained('users')->onDelete('set null');

            // ✅ Lock columns
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();

            $table->unique(
                ['student_id', 'class_id', 'subject', 'exam_type', 'academic_year'],
                'unique_student_result'
            );
            $table->index(['class_id', 'exam_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};