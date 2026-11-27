<?php

namespace App\Http\Controllers\Attendance;

use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\AcademicCalendar;
use App\Services\Attendance\Access;
use App\Services\Attendance\Grid;
use App\Services\Attendance\Register;
use App\Services\Attendance\Reports;
use App\Services\Attendance\Roster;
use App\Services\Attendance\Settings;
use App\Services\TabulationBuilder;
use App\Support\BanglaDate;
use Illuminate\Http\Request;

/** Real documents for paper: blank sheets to mark by hand, the register and every report. The browser saves them as PDF. */
class PrintController extends Controller
{
    private function paper(Request $request, string $default, TabulationBuilder $school): array
    {
        $info = $school->school();
        $asked = in_array($request->input('orientation'), ['portrait', 'landscape'], true) ? $request->input('orientation') : 'auto';
        $cal = in_array($request->input('cal'), ['english', 'bangla', 'both'], true) ? $request->input('cal') : Settings::get('date_mode');
        $reports = app(Reports::class);
        $bn = app()->getLocale() === 'bn';

        return [
            'school' => $info, 'frameColor' => $info['color'], 'hasLogo' => (bool) $info['logo'], 'asked' => $asked,
            'orientation' => $asked === 'auto' ? $default : $asked, 'cal' => $cal, 'keep' => $request->except(['orientation', 'cal']),
            'date' => fn (string $d) => $reports->date($d, $cal),
            'num' => fn ($v) => $v === null || $v === '' ? '' : ($bn ? BanglaDate::digits((string) $v) : (string) $v),
            'generated' => $reports->date(AcademicCalendar::today(), $cal),
        ];
    }

    private function classFacts(SchoolClass $class): array
    {
        return [
            'label' => $class->class_label, 'section' => $class->section_label ?: $class->section, 'year' => $class->academic_year, 'teacher' => $class->class_teacher,
            'shift' => $class->shift_id ? Shift::whereKey($class->shift_id)->value('name') : null,
            'group' => $class->study_group_id ? StudyGroup::whereKey($class->study_group_id)->value('name') : null,
        ];
    }

    private function mayPrint(User $user, SchoolClass $class): void
    {
        abort_unless(Access::can($user, 'attendance.print', $class->id), 403);
    }

    public function sheet(Request $request, TabulationBuilder $school, Register $register, Roster $roster)
    {
        $user = $this->need($request, 'attendance.print');
        $class = SchoolClass::findOrFail((int) $request->input('class'));
        $this->mayPrint($user, $class);

        $type = in_array($request->input('type'), ['daily', 'period', 'fullday'], true) ? $request->input('type') : 'daily';
        $date = $this->date($request);
        $periods = $type === 'daily' ? [] : $register->periods($class, $date);
        $period = $type === 'period' ? (collect($periods)->firstWhere('period_id', (int) $request->input('period')) ?? ($periods[0] ?? null)) : null;
        $students = $roster->students($class, $date, $period['subject_id'] ?? null);
        // which students sit each period of the day: others get a struck-out box
        $takes = [];

        if ($type === 'fullday') {
            $bySubject = [];

            foreach ($periods as $p) {
                $key = (int) $p['subject_id'];
                $bySubject[$key] ??= $roster->students($class, $date, $p['subject_id'])->pluck('id')->flip();
                $takes[$p['period_id']] = $bySubject[$key];
            }
        }

        return view('print.attendance-sheet', $this->paper($request, count($periods) > 6 && $type === 'fullday' ? 'landscape' : 'portrait', $school) + [
            'type' => $type, 'class' => $this->classFacts($class), 'day' => $date, 'periods' => $periods, 'period' => $period, 'students' => $students, 'takes' => $takes,
            'state' => app(AcademicCalendar::class)->day($date, $class->id),
        ]);
    }

    public function register(Request $request, TabulationBuilder $school, Grid $grid)
    {
        $user = $this->need($request, 'attendance.print');
        $class = SchoolClass::findOrFail((int) $request->input('class'));
        $this->mayPrint($user, $class);
        abort_unless(Access::can($user, 'attendance.view', $class->id), 403);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month')) ? $request->input('month') : substr(AcademicCalendar::today(), 0, 7);

        return view('print.attendance-register', $this->paper($request, 'landscape', $school) + [
            'class' => $this->classFacts($class), 'month' => $month, 'r' => $grid->month($user, $class, $month, $request->input('from'), $request->input('to')),
        ]);
    }

    public function report(Request $request, TabulationBuilder $school, Reports $reports)
    {
        $user = $this->need($request, 'attendance.reports');
        $year = $this->year($request);
        $f = ReportController::filtersFrom($request, $year);
        $report = $reports->build($user, (string) $request->input('type', 'students'), $f);
        $class = isset($f['class_id']) ? $reports->classes($user, $f)->first() : null;
        $subject = isset($f['subject_id']) ? \Illuminate\Support\Facades\DB::table('subjects')->where('id', $f['subject_id'])->value('name') : null;

        return view('print.attendance-report', $this->paper($request, count($report['columns']) > 7 ? 'landscape' : 'portrait', $school) + [
            'report' => $report, 'year' => $year, 'class' => $class ? $this->classFacts($class) : null, 'className' => $f['class_name'] ?? null, 'subject' => $subject,
        ]);
    }
}
