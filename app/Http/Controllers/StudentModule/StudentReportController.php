<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Jobs\RunExport;
use App\Models\AuditLog;
use App\Models\BackgroundTask;
use App\Models\Student;
use App\Models\StudyGroup;
use App\Services\StudentReports;
use App\Services\TabulationBuilder;
use App\Support\Paging;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Student Report (filter, choose columns, print, export) and Student Statistics. Needs `students.reports`. */
class StudentReportController extends Controller
{
    /** the most rows one print page or export may hold, so a request cannot exhaust memory */
    private const PRINT_LIMIT = 5000;

    public function __construct(private StudentReports $reports)
    {
    }

    // ------------------------------------------------------------------
    // student report
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $request->user();
        $sessions = $this->sessions();
        $f = $this->reports->filters($request->all(), $sessions[0]);
        $columns = $this->reports->columns($request->input('columns'));

        $full = $user->canDo('students.view_full_brn');
        $paged = $this->reports->query($f, $user)->simplePaginate(50)->withQueryString();
        $paged->setCollection($paged->getCollection()->map(fn ($s) => ['id' => $s->id] + $this->reports->row($s, $columns, $user, $full)));
        $page = Paging::shape($paged, $this->reports->count($f, $user));

        return Inertia::render('StudentModule/StudentReport', [
            'rows'      => $page,
            'filters'   => $f,
            'columns'   => $columns,
            'allColumns' => collect(StudentReports::COLUMNS)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0]])->values(),
            'sessions'  => $sessions,
            'groups'    => StudyGroup::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'statuses'  => Student::STATUSES,
            'options'   => $this->reports->options($f['session'], $user),
            'canSeeBrn' => $user->canDo('students.view_full_brn'),
        ]);
    }

    public function print(Request $request, TabulationBuilder $school)
    {
        $user = $request->user();
        $f = $this->reports->filters($request->all(), $this->sessions()[0]);
        $columns = $this->reports->columns($request->input('columns'));

        $query = $this->reports->query($f, $user);
        $count = $this->reports->count($f, $user);
        $total = $count['n'];

        $rows = $query->limit(self::PRINT_LIMIT)->get()->map(fn ($s) => $this->reports->row($s, $columns, $user));

        AuditLog::record($user, 'report_printed', ['filters' => array_filter($f), 'rows' => $rows->count()], $user->id);

        $asked = in_array($request->input('orientation'), ['portrait', 'landscape'], true) ? $request->input('orientation') : 'auto';
        $orientation = $asked === 'auto' ? (count($columns) > 7 ? 'landscape' : 'portrait') : $asked;

        return view('print.student-report', [
            'rows' => $rows, 'columns' => $columns, 'labels' => collect(StudentReports::COLUMNS)->map(fn ($c) => $c[0])->all(), 'f' => $f, 'total' => $total, 'capped' => $count['capped'], 'limit' => self::PRINT_LIMIT,
            'school' => $school->school(), 'frameColor' => $school->school()['color'], 'orientation' => $orientation, 'asked' => $asked,
            'examTypes' => [], 'examValue' => '', 'keep' => $request->except('orientation'),
        ]);
    }

    /** exports up to this many rows are made at once; larger ones run in the background with a progress bar */
    public const INLINE_ROWS = 5000;

    public function export(Request $request)
    {
        $user = $request->user();
        $f = $this->reports->filters($request->all(), $this->sessions()[0]);
        $columns = $this->reports->columns($request->input('columns'));
        $format = $request->input('format') === 'csv' ? 'csv' : 'xlsx';
        $count = $this->reports->count($f, $user);

        AuditLog::record($user, 'report_exported', ['filters' => array_filter($f), 'rows' => $count['n'] . ($count['capped'] ? '+' : ''), 'columns' => $columns], $user->id);

        $payload = ['kind' => 'students', 'filters' => $f, 'columns' => $columns, 'format' => $format, 'name' => 'students-' . $f['session'] . '.' . $format, 'sheet' => 'Students'];

        if ($count['n'] > self::INLINE_ROWS) {
            // the exact size is worked out in the job (it can take seconds on a large school); the screen shows progress from then
            $task = BackgroundTask::queue('export', $user, $payload, 0);
            RunExport::dispatch($task->id);

            return redirect()->route('sm.exports.show', $task->id);
        }

        $path = tempnam(sys_get_temp_dir(), 'ex');
        RunExport::write(RunExport::rows($payload, $user), $format, $path, 'Students');

        // Spreadsheet neutralizes cells that start with = + - @ so a name cannot run as a formula
        return response()->download($path, $payload['name'], [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    // ------------------------------------------------------------------
    // statistics
    // ------------------------------------------------------------------

    public function statistics(Request $request)
    {
        $sessions = $this->sessions();
        $session = preg_match('/^\d{4}$/', (string) $request->input('session')) ? (string) $request->input('session') : $sessions[0];

        return Inertia::render('StudentModule/StudentStatistics', [
            'sessions' => $sessions,
            'session'  => $session,
            'stats'    => $this->reports->statistics($session, $request->user(), $request->boolean('refresh')),
            'statuses' => Student::STATUSES,
        ]);
    }

    public function statisticsPrint(Request $request, TabulationBuilder $school)
    {
        $sessions = $this->sessions();
        $session = preg_match('/^\d{4}$/', (string) $request->input('session')) ? (string) $request->input('session') : $sessions[0];

        return view('print.student-statistics', [
            's' => $this->reports->statistics($session, $request->user()), 'statuses' => Student::STATUSES,
            'school' => $school->school(), 'frameColor' => $school->school()['color'], 'orientation' => 'portrait', 'asked' => 'portrait', 'examTypes' => [], 'examValue' => '',
            'keep' => ['session' => $session],
        ]);
    }

    /** the sessions that have classes, newest first (the current year if none exist yet) */
    private function sessions(): array
    {
        return \App\Models\SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all() ?: [(string) now()->year];
    }
}
