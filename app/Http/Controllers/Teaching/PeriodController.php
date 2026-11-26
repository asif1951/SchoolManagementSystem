<?php

namespace App\Http\Controllers\Teaching;

use App\Services\TabulationBuilder;
use App\Services\Teaching\Access;
use App\Services\Teaching\Calendar;
use App\Services\Teaching\PeriodView;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/** Weekly and monthly plans, on screen and on paper. */
class PeriodController extends TeachingController
{
    public function __construct(private PeriodView $view)
    {
    }

    private function params(Request $request): array
    {
        $user = $this->need($request);
        $d = $request->validate(['mode' => ['nullable', 'in:week,month,year'], 'date' => ['nullable', 'date_format:Y-m-d'], 'teacher' => ['nullable', 'integer'], 'plan' => ['nullable', 'integer'], 'session' => ['nullable', 'string', 'max:10']]);
        $mode = $d['mode'] ?? 'month';
        $date = $d['date'] ?? Calendar::today();
        $year = $d['session'] ?? substr($date, 0, 4);
        $teacher = Access::limitTo($user) ?? (isset($d['teacher']) ? (int) $d['teacher'] : null);
        [$from, $to] = $this->view->range($mode, $date, $year);
        $plans = $this->view->plans($year, ['teacher' => $teacher], $from, $to, 200);
        $planId = isset($d['plan']) ? (int) $d['plan'] : null;

        // the list is already limited to what this person may see, so choosing one plan cannot reach further
        $planId && $plans = array_values(array_filter($plans, fn ($p) => $p['id'] === $planId));

        return [$user, $mode, $date, $year, $teacher, $from, $to, $plans];
    }

    public function index(Request $request)
    {
        [$user, $mode, $date, $year, $teacher, $from, $to, $plans] = $this->params($request);
        $step = $mode === 'week' ? '7 days' : '1 month';
        $base = $mode === 'week' ? Carbon::parse($from) : Carbon::parse($from)->startOfMonth();

        return Inertia::render('Teaching/Period', [
            'mode' => $mode === 'year' ? 'month' : $mode, 'date' => $date, 'from' => $from, 'to' => $to,
            'prev' => $base->copy()->sub($step)->toDateString(), 'next' => $base->copy()->add($step)->toDateString(),
            'plans' => $plans, 'teacher' => $teacher, 'seesAll' => Access::seesAll($user),
            'teachers' => Access::seesAll($user) ? $this->teachers() : [],
            'editable' => collect($plans)->filter(fn ($p) => Access::writeFor($user, $p['teacher_id']))->pluck('id')->values(),
        ]);
    }

    public function print(Request $request, TabulationBuilder $school)
    {
        [, $mode, , $year, , $from, $to, $plans] = $this->params($request);
        $info = $school->school();
        $o = in_array($request->input('orientation'), ['portrait', 'landscape'], true) ? $request->input('orientation') : 'portrait';

        return view('print.teaching-period', [
            'plans' => $plans, 'mode' => $mode, 'from' => $from, 'to' => $to, 'year' => $year,
            'school' => $info, 'frameColor' => $info['color'], 'orientation' => $o, 'asked' => $o, 'examTypes' => [], 'examValue' => '',
            'keep' => $request->only(['mode', 'date', 'teacher', 'plan', 'session']),
        ]);
    }
}
