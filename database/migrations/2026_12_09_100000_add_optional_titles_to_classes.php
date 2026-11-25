<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many schools give their classes and sections names of their own: Class 1 is "Podma", Class 2 is "Jamuna"; section A
 * is "Rose". These are optional display names. The class name and section stay the stable keys (promotion order,
 * uniqueness, import matching); the titles are what people see next to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->string('class_title', 100)->nullable()->after('class_name');
            $table->string('section_title', 100)->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['class_title', 'section_title']);
        });
    }
};
