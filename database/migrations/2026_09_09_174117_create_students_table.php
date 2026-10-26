<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            
            // ============================================================
            // Student Information
            // ============================================================
            $table->string('student_name');
            $table->string('student_id')->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('student_photo')->nullable();
            
            // ============================================================
            // Academic Information
            // ============================================================
            $table->integer('academic_year');
            $table->string('class');
            $table->string('section')->nullable();
            $table->string('roll_number')->nullable();
            $table->date('admission_date');
            $table->string('status')->default('active');
            
            // ============================================================
            // Father's Information
            // ============================================================
            $table->string('father_name')->nullable();
            $table->string('father_phone')->nullable();
            $table->string('father_occupation')->nullable();
            
            // ============================================================
            // Mother's Information
            // ============================================================
            $table->string('mother_name')->nullable();
            $table->string('mother_phone')->nullable();
            $table->string('mother_occupation')->nullable();
            
            // ============================================================
            // Guardian Information
            // ============================================================
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_relation')->nullable();
            
            // ============================================================
            // Address
            // ============================================================
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};