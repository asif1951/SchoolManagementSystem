<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_fee_policies')) {
            return;
        }

        Schema::create('student_fee_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('fee_structure_id');
            $table->string('academic_year', 10);
            $table->string('discount_type', 20)->default('none'); // none|percent|amount|free
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            // Indexes for faster lookup
            $table->index(['student_id', 'academic_year'], 'idx_policy_student_year');
            $table->index('fee_structure_id', 'idx_policy_fee_struct');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_policies');
    }
};