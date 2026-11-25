<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The statistics group the live enrollments of a session by status / class / gender / first-admission: answered from this index alone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->index(['academic_year', 'deleted_at', 'status', 'class_id', 'gender', 'promoted_from_id'], 'idx_students_stats');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('idx_students_stats');
        });
    }
};
