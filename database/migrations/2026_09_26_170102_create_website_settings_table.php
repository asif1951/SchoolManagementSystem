<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_settings', function (Blueprint $table) {
            $table->id();

            // 🏫 School Information
            $table->string('school_name')->nullable();
            $table->string('school_motto')->nullable();
            $table->year('established_year')->nullable();
            $table->text('short_description')->nullable();
            $table->string('school_logo')->nullable();
            $table->string('favicon')->nullable();

            // 📞 Contact Details
            $table->string('phone', 30)->nullable();
            $table->string('alternative_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('office_hours')->nullable();
            $table->text('google_maps_url')->nullable();

            // 🌐 Social Links
            $table->string('facebook_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('linkedin_url')->nullable();

            // 🦶 Footer
            $table->text('footer_description')->nullable();
            $table->string('footer_copyright')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_settings');
    }
};