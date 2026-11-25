<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Totals ("1,240 students") count live enrollments of a session, optionally by status or class: answered from this index alone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->index(['academic_year', 'deleted_at', 'status', 'class_id'], 'idx_students_year_live_status_class');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('idx_students_year_live_status_class');
        });
    }
};
