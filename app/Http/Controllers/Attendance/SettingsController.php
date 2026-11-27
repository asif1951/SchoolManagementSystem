<?php

namespace App\Http\Controllers\Attendance;

use App\Models\AuditLog;
use App\Services\AcademicCalendar;
use App\Services\Attendance\Modes;
use App\Services\Attendance\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request, Modes $modes)
    {
        $user = $this->need($request, 'attendance.settings');
        $year = $this->year($request);
        $classes = $this->classes($user, 'attendance.settings', $year);
        $today = AcademicCalendar::today();

        return Inertia::render('Attendance/Settings', [
            'year' => $year, 'years' => $this->years(), 'today' => $today,
            'settings' => Settings::all(),
            'school' => $modes->school($today),
            'classes' => $this->options($classes),
            'modes' => $modes->table($classes, $today),
            'modeHistory' => $modes->history(),
            'audit' => AuditLog::query()->whereIn('auditable_type', ['attendance_mode', 'attendance_settings', (new \App\Models\Attendance\Session)->getMorphClass(), (new \App\Models\Attendance\Leave)->getMorphClass()])
                ->with('user:id,name')->latest('id')->limit(60)->get()
                ->map(fn ($a) => ['at' => $a->created_at ? (string) $a->created_at : null, 'by' => $a->user?->name, 'event' => $a->event, 'label' => $a->label, 'changes' => $a->changes])->all(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $this->need($request, 'attendance.settings');
        $d = $request->validate([
            'edit_days' => ['required', 'integer', 'between:0,365'],
            'entry_days' => ['required', 'integer', 'between:0,365'],
            'default_status' => ['required', 'in:present,none'],
            'allow_late' => ['required', 'boolean'],
            'allow_leave' => ['required', 'boolean'],
            'deadline' => ['nullable', 'date_format:H:i'],
            'period_same_day' => ['required', 'boolean'],
            'allow_substitute' => ['required', 'boolean'],
            'low_threshold' => ['required', 'numeric', 'between:1,100'],
            'consecutive_days' => ['required', 'integer', 'between:2,60'],
            'default_period' => ['required', Rule::in(['day', 'week', 'month', 'half', 'year'])],
            'date_mode' => ['required', 'in:english,bangla,both'],
            'leave_types' => ['required', 'array', 'min:1', 'max:20'],
            'leave_types.*' => ['required', 'string', 'max:60', 'distinct'],
            'report_periods' => ['present', 'array', 'max:12'],
            'report_periods.*.name' => ['required', 'string', 'max:60'],
            'report_periods.*.from' => ['required', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'report_periods.*.to' => ['required', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
        ]);

        $before = Settings::all();
        Settings::save($d);
        $changes = collect($d)->filter(fn ($v, $k) => $before[$k] != $v)->map(fn ($v, $k) => ['from' => $before[$k], 'to' => $v])->all();

        if ($changes) {
            AuditLog::create(['user_id' => $user->id, 'event' => 'attendance.settings', 'auditable_type' => 'attendance_settings', 'auditable_id' => 1, 'label' => 'Attendance settings', 'changes' => $changes, 'ip' => $request->ip(), 'created_at' => now()]);
        }

        return back()->with('success', __('attendance.msg.settings_saved'));
    }

    /** what a mode change would affect, shown before it is saved */
    public function impact(Request $request, Modes $modes)
    {
        $this->need($request, 'attendance.settings');
        $d = $this->modeInput($request);

        return response()->json($modes->impact($d['scope'], $d['mode'], $d['from']));
    }

    public function mode(Request $request, Modes $modes)
    {
        $user = $this->need($request, 'attendance.settings');
        $d = $this->modeInput($request);
        $modes->save($d['scope'], $d['mode'], $d['from'], $user->id);

        return back()->with('success', __('attendance.msg.mode_saved'));
    }

    public function cancelMode(Request $request, int $mode, Modes $modes)
    {
        $user = $this->need($request, 'attendance.settings');
        $modes->cancel($mode, $user->id);

        return back()->with('success', __('attendance.msg.mode_cancelled'));
    }

    private function modeInput(Request $request): array
    {
        $d = $request->validate([
            'scope' => ['required', 'integer', 'min:0'], 'mode' => ['nullable', Rule::in(Modes::MODES)], 'from' => ['required', 'date_format:Y-m-d'],
        ]);
        abort_if($d['scope'] && ! \App\Models\SchoolClass::whereKey($d['scope'])->exists(), 422);

        return ['scope' => (int) $d['scope'], 'mode' => $d['mode'] ?? null, 'from' => $d['from']];
    }
}
