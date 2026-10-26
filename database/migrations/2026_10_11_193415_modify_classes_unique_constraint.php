<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            // ১. পুরোনো single-composite unique drop
            $table->dropUnique('classes_class_name_section_unique');

            // ২. নতুন 3-column unique (year সহ)
            $table->unique(
                ['class_name', 'section', 'academic_year'],
                'classes_name_section_year_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique('classes_name_section_year_unique');
            $table->unique(['class_name', 'section'], 'classes_class_name_section_unique');
        });
    }
};