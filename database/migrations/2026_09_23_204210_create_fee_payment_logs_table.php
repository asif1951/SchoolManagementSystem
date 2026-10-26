<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fee_payment_logs')) {
            return;
        }

        Schema::create('fee_payment_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('academic_year', 10);
            $table->decimal('amount', 12, 2);
            $table->decimal('total_after', 12, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('collected_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payment_logs');
    }
};