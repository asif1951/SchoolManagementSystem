<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Student module permissions.
 *  - the Accountant role (new) from config/abilities.php;
 *  - class teachers may open the report of their own class.
 * Admin needs nothing: it holds "*". Every sensitive permission stays Admin-only until a school grants it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $def = config('abilities.default_roles.accountant');

        DB::table('roles')->updateOrInsert(
            ['slug' => 'accountant'],
            [
                'name'        => $def['name'],
                'description' => $def['description'],
                'abilities'   => json_encode($def['abilities']),
                'is_system'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        $teacher = DB::table('roles')->where('slug', 'teacher')->first();

        if ($teacher) {
            $abilities = json_decode((string) $teacher->abilities, true) ?: [];

            if (! in_array('students.reports:own', $abilities, true) && ! in_array('students.reports', $abilities, true)) {
                $abilities[] = 'students.reports:own';
                DB::table('roles')->where('id', $teacher->id)->update(['abilities' => json_encode(array_values($abilities)), 'updated_at' => now()]);
            }
        }

        Cache::forget(\App\Models\Role::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'accountant')->delete();
        Cache::forget(\App\Models\Role::CACHE_KEY);
    }
};
