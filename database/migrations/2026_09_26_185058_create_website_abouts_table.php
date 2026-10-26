<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_abouts', function (Blueprint $table) {
            $table->id();
            $table->string('about_title')->nullable();
            $table->string('about_subtitle')->nullable();
            $table->text('about_description')->nullable();
            $table->string('about_image')->nullable();
            $table->text('school_history')->nullable();
            $table->text('mission')->nullable();
            $table->text('vision')->nullable();
            $table->text('core_values')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_abouts');
    }
};