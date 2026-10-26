<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing duplicate rows first (keep the latest per student+year)
        if (Schema::hasTable('fee_payments')) {
            DB::statement('
                DELETE p1 FROM fee_payments p1
                INNER JOIN fee_payments p2
                WHERE p1.id < p2.id
                  AND p1.student_id = p2.student_id
                  AND p1.academic_year = p2.academic_year
            ');

            Schema::table('fee_payments', function (Blueprint $table) {
                $table->unique(['student_id', 'academic_year'], 'uniq_payment_student_year');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fee_payments')) {
            Schema::table('fee_payments', function (Blueprint $table) {
                $table->dropUnique('uniq_payment_student_year');
            });
        }
    }
};