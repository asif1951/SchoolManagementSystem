<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shifts (Morning, Day …) and mediums (Bangla, English …) are lists the admin manages.
 * A class belongs to one shift and one medium, so "Class 6 A · Morning · Bangla" and
 * "Class 6 A · Day · English" are different classes with their own students and results.
 *
 * Every class that exists today becomes Morning + Bangla, so nothing changes until the
 * admin starts using the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (MySQL cannot roll DDL back, so every step checks first and the migration can be re-run safely)
        foreach (['shifts', 'mediums'] as $table) {
            if (Schema::hasTable($table)) {
                continue;
            }

            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name', 60)->unique();
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        $now = now();
        DB::table('shifts')->count() || DB::table('shifts')->insert([
            ['name' => 'Morning', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Day', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('mediums')->count() || DB::table('mediums')->insert([
            ['name' => 'Bangla', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'English', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        if (! Schema::hasColumn('classes', 'shift_id')) {
            Schema::table('classes', function (Blueprint $t) {
                $t->foreignId('shift_id')->nullable()->after('study_group_id')->constrained('shifts')->restrictOnDelete();
                $t->foreignId('medium_id')->nullable()->after('shift_id')->constrained('mediums')->restrictOnDelete();
            });
        }

        DB::table('classes')->whereNull('shift_id')->update(['shift_id' => DB::table('shifts')->orderBy('sort_order')->value('id')]);
        DB::table('classes')->whereNull('medium_id')->update(['medium_id' => DB::table('mediums')->orderBy('sort_order')->value('id')]);

        $indexes = collect(DB::select('SHOW INDEX FROM classes'))->pluck('Key_name')->unique();

        if ($indexes->contains('classes_name_section_year_group_unique')) {
            DB::statement('ALTER TABLE classes DROP INDEX classes_name_section_year_group_unique');
        }

        // The three text columns are varchar(255), which together would overflow MySQL's key size limit,
        // so the unique key covers the first characters of each (names are never that long).
        if (! $indexes->contains('classes_identity_unique')) {
            DB::statement('ALTER TABLE classes ADD UNIQUE classes_identity_unique (class_name(100), section(60), academic_year(10), study_group_id, shift_id, medium_id)');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE classes DROP INDEX classes_identity_unique');

        Schema::table('classes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('medium_id');
            $t->dropConstrainedForeignId('shift_id');
        });

        DB::statement('ALTER TABLE classes ADD UNIQUE classes_name_section_year_group_unique (class_name(100), section(60), academic_year(10), study_group_id)');

        Schema::dropIfExists('mediums');
        Schema::dropIfExists('shifts');
    }
};
