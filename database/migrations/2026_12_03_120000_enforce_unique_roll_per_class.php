<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Roll is unique per session + class + section" — enforced by the database, not only by the form.
 *
 * A class row is already one session + class + section, so the rule is: among live (not deleted)
 * enrollments of a class, a roll number appears once. A virtual column holds the roll only while the
 * enrollment is live and non-empty, so a deleted student's roll can be reused and blank rolls never clash.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('students', 'roll_live')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->string('roll_live', 50)->nullable()->virtualAs("IF(deleted_at IS NULL, NULLIF(roll_number, ''), NULL)");
            $table->unique(['class_id', 'roll_live'], 'uniq_class_live_roll');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('uniq_class_live_roll');
            $table->dropColumn('roll_live');
        });
    }
};
