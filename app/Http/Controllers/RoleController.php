<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin screen for roles and what each role may do (Roles & Permissions).
 */
class RoleController extends Controller
{
    public function index()
    {
        $userCounts = User::query()
            ->selectRaw('role, COUNT(*) AS total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $roles = Role::query()->orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (Role $r) => [
                'id'          => $r->id,
                'slug'        => $r->slug,
                'name'        => $r->name,
                'description' => $r->description,
                'abilities'   => $r->abilities,
                'is_system'   => $r->is_system,
                'is_admin'    => $r->slug === User::ROLE_ADMIN,
                'users_count' => (int) ($userCounts[$r->slug] ?? 0),
            ]);

        return Inertia::render('Roles', [
            'roles'     => $roles,
            'catalogue' => $this->catalogue(),
            'flash'     => session()->only(['success', 'error']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'abilities'   => ['array'],
            'abilities.*' => ['string'],
        ]);

        $slug = $this->uniqueSlug($validated['name']);

        Role::create([
            'slug'        => $slug,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'abilities'   => $this->cleanAbilities($validated['abilities'] ?? []),
            'is_system'   => false,
        ]);

        return back()->with('success', "Role \"{$validated['name']}\" created.");
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'abilities'   => ['array'],
            'abilities.*' => ['string'],
        ]);

        // The admin role always has everything; it cannot be weakened.
        $abilities = $role->slug === User::ROLE_ADMIN
            ? ['*']
            : $this->cleanAbilities($validated['abilities'] ?? []);

        $role->update([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'abilities'   => $abilities,
        ]);

        return back()->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return back()->with('error', 'Built-in roles cannot be deleted.');
        }

        if (User::where('role', $role->slug)->exists()) {
            return back()->with('error', 'This role is still assigned to users. Move them to another role first.');
        }

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    // ------------------------------------------------------------------

    /** Keep only abilities that exist in the catalogue (and ":own" only where it makes sense). */
    private function cleanAbilities(array $abilities): array
    {
        $allowed = [];

        foreach (config('abilities.groups') as $group) {
            foreach ($group as $key => $meta) {
                $allowed[$key] = true;
                if ($meta['scoped']) {
                    $allowed[$key . ':own'] = true;
                }
            }
        }

        $clean = array_values(array_unique(array_filter($abilities, fn ($a) => isset($allowed[$a]))));

        // "whole school" wins over "own classes only" for the same ability.
        return array_values(array_filter(
            $clean,
            fn ($a) => ! (str_ends_with($a, ':own') && in_array(substr($a, 0, -4), $clean, true))
        ));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'role';
        $slug = $base;
        $i    = 2;

        while (Role::where('slug', $slug)->exists()) {
            $slug = $base . '_' . $i++;
        }

        return $slug;
    }

    /** Catalogue shaped for the editor. */
    private function catalogue(): array
    {
        $out = [];

        foreach (config('abilities.groups') as $group => $abilities) {
            $out[] = [
                'group'     => $group,
                'abilities' => collect($abilities)->map(fn ($meta, $key) => [
                    'key'    => $key,
                    'label'  => $meta['label'],
                    'scoped' => $meta['scoped'],
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
