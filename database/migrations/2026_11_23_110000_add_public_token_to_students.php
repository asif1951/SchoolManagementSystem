<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Unguessable token for the public QR verify page, so student records
 * can no longer be enumerated through sequential ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('public_token', 26)->nullable()->after('student_id');
        });

        DB::table('students')->whereNull('public_token')->orderBy('id')->each(function ($row) {
            DB::table('students')->where('id', $row->id)->update(['public_token' => (string) Str::ulid()]);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unique('public_token', 'students_public_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_public_token_unique');
            $table->dropColumn('public_token');
        });
    }
};
