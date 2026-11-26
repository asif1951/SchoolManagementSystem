<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Teachers get their own Teaching Plan; a "Head Teacher" role is created to see, approve and review everything. */
return new class extends Migration
{
    public function up(): void
    {
        $teacher = DB::table('roles')->where('slug', 'teacher')->first();

        if ($teacher) {
            $abilities = json_decode((string) $teacher->abilities, true) ?: [];

            if (! in_array('*', $abilities, true)) {
                $abilities = array_values(array_unique([...$abilities, 'teaching.own']));
                DB::table('roles')->where('id', $teacher->id)->update(['abilities' => json_encode($abilities), 'updated_at' => now()]);
            }
        }

        if (! DB::table('roles')->where('slug', 'head_teacher')->exists()) {
            $def = config('abilities.default_roles.head_teacher');

            DB::table('roles')->insert(['slug' => 'head_teacher', 'name' => $def['name'], 'description' => $def['description'],
                'abilities' => json_encode($def['abilities']), 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        Cache::forget('roles.map');
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = array_values(array_filter(json_decode((string) $role->abilities, true) ?: [], fn ($a) => ! str_starts_with($a, 'teaching.')));
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($abilities)]);
        }

        Cache::forget('roles.map');
    }
};
