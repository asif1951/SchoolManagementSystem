<?php

namespace App\Http\Controllers\Attendance;

use App\Models\Attendance\Leave;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AcademicCalendar;
use App\Services\Attendance\Access;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Grid;
use App\Services\Attendance\Register;
use App\Services\Attendance\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RecordController extends Controller
{
    /** the submitted sheets, newest first */
    public function index(Request $request, Register $register)
    {
        $user = $this->need($request, 'attendance.view');
        $year = $this->year($request);
        $classes = $this->classes($user, 'attendance.view', $year);
        $today = AcademicCalendar::today();
        $from = $this->date($request, 'from', "{$year}-01-01");
        $to = $this->date($request, 'to', "{$year}-12-31");
        $classId = (int) $request->input('class');
        $ids = $classes->pluck('id')->when($classId, fn ($c) => $c->intersect([$classId]))->values()->all();
        $mode = in_array($request->input('mode'), ['daily', 'period'], true) ? $request->input('mode') : null;

        $page = DB::table('attendance_sessions as s')->leftJoin('users as u', 'u.id', '=', 's.taken_by')->leftJoin('users as m', 'm.id', '=', 's.updated_by')
            ->whereIn('s.class_id', $ids ?: [0])->whereBetween('s.date', [$from, $to])->when($mode, fn ($q) => $q->where('s.mode', $mode))
            // a subject teacher sees their own subjects' periods
            ->where(function ($q) use ($user, $ids) {
                $q->whereRaw('1 = 0');

                foreach ($ids as $id) {
                    $limit = Access::subjectLimit($user, 'attendance.view', $id);
                    $q->orWhere(fn ($w) => $w->where('s.class_id', $id)->when($limit !== null, fn ($x) => $x->whereIn('s.subject_id', $limit ?: [0])));
                }
            })
            ->orderByDesc('s.date')->orderBy('s.class_id')->orderBy('s.starts_at')
            ->select(['s.*', 'u.name as taken_name', 'm.name as updated_name'])->paginate(30)->withQueryString();

        $counts = DB::table('attendance_records')->whereIn('session_id', collect($page->items())->pluck('id'))->groupBy('session_id')
            ->selectRaw("session_id, SUM(status = 'present') present, SUM(status = 'absent') absent, SUM(status = 'late') late, SUM(status = 'leave') on_leave, COUNT(*) total")->get()->keyBy('session_id');
        $labels = collect($this->options($classes))->pluck('label', 'id');

        $page->through(fn ($s) => [
            'id' => $s->id, 'class_id' => $s->class_id, 'class' => $labels[$s->class_id] ?? '?', 'date' => substr($s->date, 0, 10), 'mode' => $s->mode,
            'period_id' => $s->period_id, 'period' => $s->period_name, 'subject' => $s->subject_name, 'teacher' => $s->teacher_name, 'substitute' => (bool) $s->substitute,
            'taken_by' => $s->taken_name, 'submitted_at' => $s->submitted_at, 'updated_by' => $s->updated_name, 'updated_at' => $s->updated_name ? $s->updated_at : null,
            'late_submission' => (bool) $s->late_submission, 'legacy' => $s->source === 'legacy', 'day_type' => $s->day_type,
        ] + Calculator::figures($counts[$s->id] ?? null));

        return Inertia::render('Attendance/Records', [
            'year' => $year, 'years' => $this->years(), 'classes' => $this->options($classes),
            'filters' => ['class' => $classId ?: '', 'from' => $from, 'to' => $to, 'mode' => $mode ?? ''],
            'sessions' => $page,
            'conflicts' => collect($register->conflicts($ids, $from, min($to, $today)))->map(fn ($c) => $c + ['class' => $labels[$c['class_id']] ?? '?'])->all(),
        ]);
    }

    public function register(Request $request, Grid $grid)
    {
        $user = $this->need($request, 'attendance.view');
        $year = $this->year($request);
        $classes = $this->classes($user, 'attendance.view', $year);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month')) ? $request->input('month')
            : ($year === substr(AcademicCalendar::today(), 0, 4) ? substr(AcademicCalendar::today(), 0, 7) : "{$year}-12");
        $class = $classes->firstWhere('id', (int) $request->input('class'));
        abort_if($request->filled('class') && ! $class, 403);

        $data = null;

        if ($class) {
            $data = $grid->month($user, $class, $month, $request->input('from'), $request->input('to'));
            $page = max(1, (int) $request->input('page', 1));
            $data['pages'] = max(1, (int) ceil(count($data['students']) / 50));
            $data['page'] = min($page, $data['pages']);
            $data['students'] = array_slice($data['students'], ($data['page'] - 1) * 50, 50);
        }

        return Inertia::render('Attendance/Register', [
            'year' => $year, 'years' => $this->years(), 'classes' => $this->options($classes), 'month' => $month,
            'filters' => ['class' => $class?->id ?? '', 'from' => $request->input('from', ''), 'to' => $request->input('to', '')], 'register' => $data,
        ]);
    }

    /** the attendance of one student, shown on their profile */
    public function student(Request $request, Student $student, Calculator $calc)
    {
        $user = $this->need($request, 'attendance.view');
        $classId = (int) $student->class_id;
        abort_unless(Access::can($user, 'attendance.view', $classId), 403);

        $year = substr((string) $student->academic_year, 0, 4);
        $limit = Access::subjectLimit($user, 'attendance.view', $classId);
        $q = ['from' => "{$year}-01-01", 'to' => "{$year}-12-31", 'student_ids' => [$student->id], 'subject_limits' => $limit === null ? [] : [$classId => $limit]];
        $days = $calc->days($q);

        return response()->json([
            'summary' => $calc->students($q)[$student->id] ?? null,
            'threshold' => (float) Settings::get('low_threshold'),
            'months' => collect($days)->map(fn ($d) => substr($d['date'], 0, 7))->unique()->sort()->values()
                ->map(fn ($m) => ['month' => $m] + ($calc->students(['from' => "{$m}-01", 'to' => date('Y-m-t', strtotime("{$m}-01"))] + $q)[$student->id] ?? []))->all(),
            'subjects' => $calc->query(['mode' => 'period'] + $q)->whereNotNull('s.subject_id')->groupBy('s.subject_id', 's.subject_name')
                ->selectRaw("s.subject_name name, SUM(r.status = 'present') present, SUM(r.status = 'absent') absent, SUM(r.status = 'late') late, SUM(r.status = 'leave') on_leave, COUNT(*) total")->get()
                ->map(fn ($r) => ['name' => $r->name] + Calculator::figures($r))->all(),
            'days' => array_slice(array_reverse($days), 0, 60),
            'leaves' => Leave::where('student_id', $student->id)->orderByDesc('starts_on')->limit(20)->get()
                ->map(fn ($l) => ['id' => $l->id, 'type' => $l->type, 'from' => $l->starts_on->toDateString(), 'to' => $l->ends_on->toDateString(), 'status' => $l->status])->all(),
        ]);
    }

    /** the dashboard box: today's sheets by class, and how many students are below the line */
    public function today(Request $request, Register $register, Calculator $calc)
    {
        $user = $this->need($request, 'attendance.view');
        $date = AcademicCalendar::today();
        $year = substr($date, 0, 4);
        $classes = $this->classes($user, 'attendance.view', $year);
        $status = $register->status($classes, $date);
        // low attendance is for those who look after whole classes
        $own = $classes->filter(fn ($c) => Access::wholeClass($user, 'attendance.view', $c->id))->pluck('id')->all();
        $limit = (float) Settings::get('low_threshold');
        $low = $own ? Cache::remember('attendance.low.' . md5(json_encode([$own, $date, $limit])), 600, fn () => count(array_filter(
            $calc->students(['from' => "{$year}-01-01", 'to' => $date, 'class_ids' => $own]), fn ($s) => $s['percent'] !== null && $s['percent'] < $limit))) : null;

        return response()->json([
            'date' => $date,
            'classes' => collect($this->options($classes))->map(fn ($c) => ['id' => $c['id'], 'label' => $c['label']] + $status[$c['id']])->all(),
            'low' => $low, 'threshold' => $limit,
            // the dashboard sends no translations of its own, so the box brings its words
            'words' => ['title' => __('attendance.today_title'), 'open' => __('attendance.open'), 'closed' => __('attendance.state.closed'),
                'state' => __('attendance.state'), 'progress' => __('attendance.today_progress'), 'low' => __('attendance.low_students')],
        ]);
    }
}
