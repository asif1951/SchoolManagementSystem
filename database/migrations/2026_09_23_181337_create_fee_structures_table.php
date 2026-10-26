<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fee_structures')) {
            return;
        }

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('academic_year', 10);
            $table->string('fee_name', 100);
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('cycle', 50)->default('Monthly');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Index for fast lookups
            $table->index(['class_id', 'academic_year']);
            $table->index(['academic_year', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};