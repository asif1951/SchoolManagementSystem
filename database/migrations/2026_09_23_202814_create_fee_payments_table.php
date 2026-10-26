<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fee_payments')) {
            return;
        }

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('academic_year', 10);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('collected_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'academic_year'], 'idx_payment_student_year');
            $table->index('collected_by', 'idx_payment_collector');
            $table->index('paid_at', 'idx_payment_paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
    }
};