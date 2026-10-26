<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_heroes', function (Blueprint $table) {
            $table->id();

            // Hero Content
            $table->string('hero_title')->nullable();
            $table->text('hero_subtitle')->nullable();

            // Hero Image
            $table->string('hero_image')->nullable();
            $table->string('hero_image_alt')->nullable();

            // Button 1
            $table->boolean('button1_show')->default(true);
            $table->string('button1_text')->nullable();
            $table->enum('button1_type', ['internal', 'external'])->default('internal');
            $table->string('button1_page')->nullable();
            $table->string('button1_url')->nullable();

            // Button 2
            $table->boolean('button2_show')->default(true);
            $table->string('button2_text')->nullable();
            $table->enum('button2_type', ['internal', 'external'])->default('internal');
            $table->string('button2_page')->nullable();
            $table->string('button2_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_heroes');
    }
};