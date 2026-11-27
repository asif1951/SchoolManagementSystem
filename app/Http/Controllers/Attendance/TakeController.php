<?php

namespace App\Http\Controllers\Attendance;

use App\Models\SchoolClass;
use App\Services\Attendance\Access;
use App\Services\Attendance\Register;
use App\Services\Attendance\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TakeController extends Controller
{
    public function index(Request $request, Register $register)
    {
        $user = $this->need($request, 'attendance.view', 'attendance.take');

        // a person who only reports goes where their work is
        $year = $this->year($request);
        $date = $this->date($request);
        $classes = $this->classes($user, $user->canDo('attendance.take') ? 'attendance.take' : 'attendance.view', $request->filled('year') ? $year : substr($date, 0, 4));
        $class = $request->filled('class') ? SchoolClass::findOrFail((int) $request->input('class')) : null;
        $sheet = null;

        if ($class) {
            abort_unless(Access::can($user, 'attendance.view', $class->id) || Access::can($user, 'attendance.take', $class->id), 403);
            $sheet = $register->sheet($user, $class, $date, $request->filled('period') ? (int) $request->input('period') : null);
        }

        $status = $class ? [] : $register->status($classes, $date);

        return Inertia::render('Attendance/Take', [
            'year' => substr($date, 0, 4), 'years' => $this->years(), 'date' => $date,
            'classes' => collect($this->options($classes))->map(fn ($c) => $c + ['status' => $status[$c['id']] ?? null])->all(),
            'classInfo' => $class ? $this->options(collect([$class]))[0] : null,
            'sheet' => $sheet,
            'conflicts' => ! $class && $user->canDo('attendance.settings')
                ? $register->conflicts(null, substr($date, 0, 4) . '-01-01', substr($date, 0, 4) . '-12-31', 20) : [],
            'defaultStatus' => Settings::get('default_status'),
        ]);
    }

    public function store(Request $request, Register $register)
    {
        $this->need($request, 'attendance.take', 'attendance.edit', 'attendance.edit_old');
        $d = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'period_id' => ['nullable', 'integer'],
            'revision' => ['nullable', 'integer'],
            'entries' => ['required', 'array', 'max:1000'],
            'entries.*.student_id' => ['required', 'integer'],
            'entries.*.status' => ['nullable', 'in:present,absent,late,leave'],
            'entries.*.remark' => ['nullable', 'string', 'max:255'],
        ]);

        $session = $register->save($request->user(), SchoolClass::findOrFail($d['class_id']), $d['date'], $d['period_id'] ?? null, $d['entries'], $d['revision'] ?? null);

        return back()->with('success', __($session->revision > 1 ? 'attendance.msg.updated' : 'attendance.msg.saved'));
    }
}
