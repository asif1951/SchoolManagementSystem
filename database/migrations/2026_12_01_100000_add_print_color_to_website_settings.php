<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The colour of the border printed around every result document (tabulation, marksheet, summaries). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('website_settings', 'print_color')) {
            Schema::table('website_settings', function (Blueprint $table) {
                $table->string('print_color', 7)->default('#1f3a5f')->after('school_logo');
            });
        }
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            $table->dropColumn('print_color');
        });
    }
};
