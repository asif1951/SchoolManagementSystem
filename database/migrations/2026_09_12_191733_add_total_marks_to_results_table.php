<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            if (!Schema::hasColumn('results', 'total_marks')) {
                $table->integer('total_marks')->nullable()->after('marks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            if (Schema::hasColumn('results', 'total_marks')) {
                $table->dropColumn('total_marks');
            }
        });
    }
};