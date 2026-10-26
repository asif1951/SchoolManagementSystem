<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('photo')->nullable();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->string('qualification')->nullable();
            $table->string('experience')->nullable();
            $table->text('bio')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('display_on_website')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index('status');
            $table->index('display_on_website');
            $table->index('order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};