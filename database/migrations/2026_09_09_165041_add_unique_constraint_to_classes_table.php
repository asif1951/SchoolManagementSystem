<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            // Drop existing unique constraint on class_name if exists
            $table->dropUnique(['class_name']);
            
            // Add unique constraint on class_name and section together
            $table->unique(['class_name', 'section']);
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique(['class_name', 'section']);
            $table->unique(['class_name']);
        });
    }
};