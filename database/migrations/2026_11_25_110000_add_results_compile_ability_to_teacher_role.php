<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "results.compile" = see the complete class result (all subjects, totals, ranking).
 * Class teachers keep that for their own classes; subject-only teachers do not get it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('slug', 'teacher')->first();

        if ($role) {
            $abilities = json_decode($role->abilities, true) ?: [];

            if (! in_array('results.compile:own', $abilities, true) && ! in_array('results.compile', $abilities, true)) {
                $abilities[] = 'results.compile:own';
                DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($abilities), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', 'teacher')->first();

        if ($role) {
            $abilities = array_values(array_diff(json_decode($role->abilities, true) ?: [], ['results.compile:own']));
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($abilities)]);
        }
    }
};
