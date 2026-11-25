<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Subject teachers finish their subject by locking it, so teachers need results.lock (own classes). */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('slug', 'teacher')->first();

        if ($role) {
            $abilities = json_decode($role->abilities, true) ?: [];

            if (! in_array('results.lock:own', $abilities, true) && ! in_array('results.lock', $abilities, true)) {
                $abilities[] = 'results.lock:own';
                DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($abilities), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', 'teacher')->first();

        if ($role) {
            $abilities = array_values(array_diff(json_decode($role->abilities, true) ?: [], ['results.lock:own']));
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($abilities)]);
        }
    }
};
