<?php

namespace App\Http\Controllers\Teaching;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Teaching\Access;
use App\Services\Teaching\Insights;
use App\Services\Teaching\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingsController extends TeachingController
{
    public function index(Request $request)
    {
        $user = $this->need($request, 'teaching.settings');

        return Inertia::render('Teaching/Settings', [
            'settings' => Settings::all(),
            // personal permissions on top of the role
            'abilities' => collect(config('abilities.groups.Teaching Plan'))->map(fn ($a, $key) => ['key' => $key, 'label' => $a['label']])->values(),
            'people' => User::where('role', '!=', User::ROLE_USER)->where('status', User::STATUS_ACTIVE)->orderBy('name')->limit(1000)->get(['id', 'name', 'role', 'extra_abilities'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'extra' => array_values($u->extra_abilities ?? [])]),
            // handing out permissions needs the permission to manage roles
            'canGrant' => $user->canDo('roles.manage'),
            'flash' => $this->flash(),
        ]);
    }

    public function save(Request $request)
    {
        $this->need($request, 'teaching.settings');
        $d = $request->validate([
            'approval_required' => ['required', 'boolean'], 'edit_window_days' => ['required', 'integer', 'between:0,365'],
            'amber_gap' => ['required', 'integer', 'between:0,100'], 'red_gap' => ['required', 'integer', 'between:0,100', 'gte:amber_gap'],
        ]);
        $before = Settings::all();

        DB::transaction(function () use ($request, $d, $before) {
            Settings::save($d);
            AuditLog::record($request->user(), 'teach.settings', ['from' => array_intersect_key($before, $d), 'to' => $d]);
            DB::table('teaching_plans')->update(['summary_on' => null]);        // colours follow the new thresholds
        });
        Insights::touch();

        return back()->with('success', __('teaching.msg.settings_saved'));
    }

    public function grant(Request $request, User $user)
    {
        $me = $this->need($request, 'teaching.settings');
        abort_unless($me->canDo('roles.manage'), 403);
        abort_if($user->role === User::ROLE_USER, 422);
        $d = $request->validate(['abilities' => ['present', 'array'], 'abilities.*' => [Rule::in(Access::ABILITIES)]]);
        $before = $user->extra_abilities ?? [];
        // only this module's permissions are touched here
        $other = array_values(array_filter($before, fn ($a) => ! str_starts_with($a, 'teaching.')));

        DB::transaction(function () use ($user, $d, $other, $before) {
            $user->forceFill(['extra_abilities' => array_values(array_unique([...$other, ...$d['abilities']])) ?: null])->save();
            AuditLog::record($user, 'teach.permission', ['from' => $before, 'to' => $d['abilities']]);
        });

        return back()->with('success', __('teaching.msg.permissions_saved'));
    }
}
