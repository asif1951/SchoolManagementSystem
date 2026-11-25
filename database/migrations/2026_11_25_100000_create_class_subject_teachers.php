<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who teaches which subject in which class (one row per teacher stint).
 * Classes are one row per academic year, so each year has its own subject teachers,
 * and changes inside a year keep their history (ends_on NULL = current).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_subject_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('subject', 100);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('teacher_name', 255);               // snapshot — survives renames/deletes
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['class_id', 'subject', 'ends_on'], 'idx_cst_class_subject_current');
            $table->index(['user_id', 'ends_on'], 'idx_cst_user_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subject_teachers');
    }
};
