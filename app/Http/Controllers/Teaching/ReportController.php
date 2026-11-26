<?php

namespace App\Http\Controllers\Teaching;

use App\Jobs\ExportTeachingReport;
use App\Models\BackgroundTask;
use App\Models\Subject;
use App\Services\TabulationBuilder;
use App\Services\Teaching\Access;
use App\Services\Teaching\Progress;
use App\Services\Teaching\Reports;
use App\Services\Teaching\Rows;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends TeachingController
{
    private const PRINT_LIMIT = 2000;

    public function __construct(private Reports $reports, private Progress $progress)
    {
    }

    private function params(Request $request): array
    {
        $user = $this->need($request);
        $years = $this->years();
        $year = $this->year($request, $years);
        $report = in_array($request->input('report'), Reports::KEYS, true) ? $request->input('report') : 'progress';
        $this->progress->ensureFresh($year);

        return [$user, $years, $report, $this->reports->filters($request->all(), $user, $year)];
    }

    public function index(Request $request, Rows $rows)
    {
        [$user, $years, $report, $filters] = $this->params($request);
        $page = max(1, min(10000, (int) $request->input('page', 1)));

        return Inertia::render('Teaching/Reports', [
            'report' => $report, 'filters' => $filters, 'page' => $page, 'years' => $years,
            'headings' => $this->reports->headings($report),
            'dated' => in_array($report, Reports::DATED, true),
            ...$this->reports->page($report, $filters, $page),
            'seesAll' => Access::seesAll($user), 'teachers' => Access::seesAll($user) ? $this->teachers() : [],
            'classes' => $rows->classes($filters['session']),
            'subjects' => Subject::query()->toBase()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function print(Request $request, TabulationBuilder $school)
    {
        [, , $report, $filters] = $this->params($request);
        $data = $this->reports->page($report, $filters, 1, self::PRINT_LIMIT);
        $info = $school->school();
        $o = in_array($request->input('orientation'), ['portrait', 'landscape'], true) ? $request->input('orientation') : 'landscape';

        return view('print.teaching-report', [
            'report' => $report, 'filters' => $filters, 'dated' => in_array($report, Reports::DATED, true), 'headings' => $this->reports->headings($report), 'rows' => $data['rows'], 'cut' => $data['more'],
            'school' => $info, 'frameColor' => $info['color'], 'orientation' => $o, 'asked' => $o, 'examTypes' => [], 'examValue' => '',
            'keep' => $request->only(['report', 'session', 'from', 'to', 'teacher', 'class', 'subject', 'health']),
        ]);
    }

    public function export(Request $request)
    {
        [$user, , $report, $filters] = $this->params($request);
        $task = BackgroundTask::queue('export', $user, ['report' => $report, 'filters' => $filters, 'locale' => app()->getLocale()]);
        ExportTeachingReport::dispatch($task->id);

        return response()->json(['task' => $task->id]);
    }
}
