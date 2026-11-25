<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles live in the database so the admin can edit what each role may do.
 * `users.role` keeps holding the role slug — nothing else about users changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->json('abilities');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // Required system data (not demo data): the built-in roles and their default abilities.
        $system = config('abilities.system_roles', []);

        foreach (config('abilities.default_roles', []) as $slug => $role) {
            DB::table('roles')->insert([
                'slug'        => $slug,
                'name'        => $role['name'],
                'description' => $role['description'] ?? null,
                'abilities'   => json_encode($role['abilities']),
                'is_system'   => in_array($slug, $system, true),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Any other role string already used by a user keeps working as a role with no abilities.
        $known = array_keys(config('abilities.default_roles', []));
        foreach (DB::table('users')->whereNotIn('role', $known)->distinct()->pluck('role') as $slug) {
            DB::table('roles')->insert([
                'slug' => $slug, 'name' => ucfirst((string) $slug), 'description' => 'Imported role',
                'abilities' => json_encode([]), 'is_system' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
