<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Teachers may see the published routines (their own timetable); only Admin (or whoever is granted it) makes and publishes them. */
return new class extends Migration
{
    public function up(): void
    {
        $teacher = DB::table('roles')->where('slug', 'teacher')->first();

        if ($teacher) {
            $abilities = json_decode((string) $teacher->abilities, true) ?: [];

            if (! in_array('routine.view', $abilities, true) && ! in_array('*', $abilities, true)) {
                $abilities[] = 'routine.view';
                DB::table('roles')->where('id', $teacher->id)->update(['abilities' => json_encode(array_values($abilities)), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $teacher = DB::table('roles')->where('slug', 'teacher')->first();

        if ($teacher) {
            $abilities = array_values(array_diff(json_decode((string) $teacher->abilities, true) ?: [], ['routine.view']));
            DB::table('roles')->where('id', $teacher->id)->update(['abilities' => json_encode($abilities), 'updated_at' => now()]);
        }
    }
};
