<?php

namespace App\Http\Controllers\Attendance;

use App\Jobs\ExportAttendanceReport;
use App\Models\BackgroundTask;
use App\Models\RoutinePeriod;
use App\Models\Shift;
use App\Models\Student;
use App\Models\StudyGroup;
use App\Services\Attendance\Periods;
use App\Services\Attendance\Reports;
use App\Services\Attendance\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ReportController extends Controller
{
    private const PER_PAGE = 50;

    /** larger exports are written in the background */
    private const AT_ONCE = 3000;

    public static function filtersFrom(Request $request, string $year): array
    {
        $f = ['year' => $year];

        foreach (['period', 'date', 'from', 'to', 'class_name', 'class_id', 'group_id', 'shift_id', 'student_id', 'subject_id', 'period_id', 'status'] as $k) {
            $v = $request->input($k);

            if (is_scalar($v) && (string) $v !== '') {
                $f[$k] = mb_substr((string) $v, 0, 60);
            }
        }

        foreach (['date', 'from', 'to'] as $k) {
            if (isset($f[$k]) && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k]) || strtotime($f[$k]) === false)) {
                unset($f[$k]);
            }
        }

        return $f;
    }

    public function index(Request $request, Reports $reports, Periods $periods)
    {
        $user = $this->need($request, 'attendance.reports');
        $year = $this->year($request);
        $type = isset(Reports::TYPES[$request->input('type')]) ? $request->input('type') : 'students';
        $f = self::filtersFrom($request, $year);
        $classes = $this->classes($user, 'attendance.reports', $year);
        $report = $reports->build($user, $type, $f);

        // search, sort and page on the server: only one page travels
        $rows = collect($report['rows']);
        $search = mb_strtolower(trim((string) $request->input('search', '')));

        if ($search !== '') {
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower(implode(' ', array_filter($r, 'is_scalar'))), $search));
        }

        $sort = (string) $request->input('sort', '');

        if (in_array($sort, array_column($report['columns'], 'key'), true)) {
            $rows = $rows->sortBy($sort, SORT_NATURAL | SORT_FLAG_CASE, $request->input('dir') === 'desc');
        }

        $total = $rows->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));
        $report['rows'] = $rows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values()->all();

        $ids = $classes->pluck('id');
        $periodWise = DB::table('attendance_sessions')->whereIn('class_id', $ids)->where('mode', 'period')->exists()
            || DB::table('attendance_modes')->where('mode', 'period')->exists();

        return Inertia::render('Attendance/Reports', [
            'year' => $year, 'years' => $this->years(), 'type' => $type, 'report' => $report,
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total],
            'types' => collect(Reports::TYPES)->filter(fn ($g) => $g !== 'period' || $periodWise)->all(),
            'filters' => $f + ['search' => $search, 'sort' => $sort, 'dir' => $request->input('dir', 'asc'), 'period' => $f['period'] ?? Settings::get('default_period')],
            'options' => [
                'threshold' => (float) Settings::get('low_threshold'),
                'classes' => $this->options($classes), 'parts' => $periods->parts($year),
                'groups' => StudyGroup::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
                'shifts' => Shift::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
                'subjects' => $periodWise ? DB::table('attendance_sessions as s')->whereIn('s.class_id', $ids)->whereNotNull('s.subject_id')->distinct()->orderBy('s.subject_name')->get(['s.subject_id as id', 's.subject_name as name'])->unique('id')->values() : [],
                'periods' => $periodWise ? RoutinePeriod::whereIn('id', DB::table('attendance_sessions')->whereIn('class_id', $ids)->whereNotNull('period_id')->distinct()->pluck('period_id'))->orderBy('starts_at')->get(['id', 'name']) : [],
                'student' => isset($f['student_id']) ? Student::whereIn('class_id', $ids)->find($f['student_id'])?->only(['id', 'student_name', 'roll_number']) : null,
            ],
            'canExport' => $user->canDo('attendance.export'), 'canPrint' => $user->canDo('attendance.print') || $user->canDo('attendance.reports'),
        ]);
    }

    /** students to pick for the student filter */
    public function students(Request $request)
    {
        $user = $this->need($request, 'attendance.reports');
        $q = trim((string) $request->input('q', ''));
        $ids = $this->classes($user, 'attendance.reports', $this->year($request))->pluck('id');

        return response()->json($q === '' ? [] : Student::whereIn('class_id', $ids)
            ->where(fn ($w) => $w->where('student_name', 'like', "%{$q}%")->orWhere('student_id', 'like', "{$q}%")->orWhere('roll_number', $q))
            ->orderBy('class_id')->orderBy('roll_number')->limit(20)->get(['id', 'student_name', 'roll_number', 'student_id']));
    }

    public function export(Request $request, Reports $reports)
    {
        $user = $this->need($request, 'attendance.export');
        $year = $this->year($request);
        $type = isset(Reports::TYPES[$request->input('type')]) ? $request->input('type') : 'students';
        $format = $request->input('format') === 'csv' ? 'csv' : 'xlsx';
        $f = self::filtersFrom($request, $year);

        // exporting is limited to the classes the export permission covers
        $allowed = $this->classes($user, 'attendance.export', $year)->pluck('id');
        abort_if(isset($f['class_id']) && ! $allowed->contains((int) $f['class_id']), 403);
        $report = $reports->build($user, $type, $f);

        $payload = ['type' => $type, 'filters' => $f, 'format' => $format, 'locale' => app()->getLocale()];

        if (count($report['rows']) > self::AT_ONCE) {
            $task = BackgroundTask::queue('attendance_export', $user, $payload, count($report['rows']));
            ExportAttendanceReport::dispatch($task->id);

            return response()->json(['task' => $task->id]);
        }

        Storage::disk('local')->makeDirectory('exports');
        $file = Storage::disk('local')->path('exports/att-' . uniqid('', true) . ".{$format}");
        ExportAttendanceReport::write($report, $format, $file);

        return response()->download($file, "attendance-{$type}-{$report['range']['from']}-{$report['range']['to']}.{$format}")->deleteFileAfterSend();
    }

    public function task(Request $request, BackgroundTask $task)
    {
        abort_unless($task->type === 'attendance_export' && $task->user_id === $request->user()->id, 404);

        return response()->json($task->toProgress() + ['summary' => $task->summary, 'status' => $task->status]);
    }

    public function download(Request $request, BackgroundTask $task)
    {
        $this->need($request, 'attendance.export');
        abort_unless($task->type === 'attendance_export' && $task->user_id === $request->user()->id, 404);
        $file = $task->summary['file'] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file), 404);

        return Storage::disk('local')->download($file, $task->summary['name']);
    }
}
