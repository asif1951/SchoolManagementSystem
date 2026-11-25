<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance can be part of the marking system. `attendance_marks` is the number of marks a
 * perfect attendance earns in a year's result (0 = attendance is reported but earns no marks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('result_policies', function (Blueprint $table) {
            $table->decimal('attendance_marks', 5, 2)->default(0)->after('optional_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('result_policies', function (Blueprint $table) {
            $table->dropColumn('attendance_marks');
        });
    }
};
