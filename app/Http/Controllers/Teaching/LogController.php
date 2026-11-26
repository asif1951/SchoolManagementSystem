<?php

namespace App\Http\Controllers\Teaching;

use App\Models\Teaching\TeachingLog;
use App\Services\Teaching\Access;
use App\Services\Teaching\Calendar;
use App\Services\Teaching\DailyLog;
use App\Services\Teaching\Insights;
use App\Services\Teaching\Rows;
use App\Services\Teaching\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LogController extends TeachingController
{
    public function __construct(private DailyLog $log)
    {
    }

    public function index(Request $request, Calendar $calendar, Insights $insights, Rows $rows)
    {
        $user = $this->need($request);
        $today = Calendar::today();
        $date = (string) $request->input('date', $today);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? min($date, $today) : $today;
        $seesAll = Access::seesAll($user);
        $teacherId = $seesAll ? ((int) $request->input('teacher') ?: $user->id) : $user->id;
        $closed = collect($calendar->closedRanges($date, $date))->pluck(2)->all();

        return Inertia::render('Teaching/DailyLog', [
            'date' => $date, 'today' => $today, 'teacherId' => $teacherId, 'seesAll' => $seesAll,
            'teachers' => $this->teachers(),
            'closed' => $closed,
            'canWrite' => Access::writeFor($user, $teacherId) && $this->log->editable($user, $date, $today),
            'locked' => Access::writeFor($user, $teacherId) && ! $this->log->editable($user, $date, $today),
            ...$this->log->day($teacherId, $date),
            'todo' => $teacherId === $user->id && $user->canDo('teaching.own') ? $this->todo($user, $today, $insights, $rows) : null,
            'unread' => $this->notices($user),
            'flash' => $this->flash(),
        ]);
    }

    /** the teacher's own loose ends: plans not made yet, days not logged, subjects behind */
    private function todo($user, string $today, Insights $insights, Rows $rows): array
    {
        $year = substr($today, 0, 4);
        $from = Carbon::parse($today)->subDays((int) Settings::get('edit_window_days'))->toDateString();
        $behind = $rows->plans($year, ['teacher' => $user->id])->whereIn('p.health', ['red', 'amber'])->orderBy('p.gap')->limit(4)->get()->all();

        return [
            'session' => $year,
            'missing' => count($rows->missing($year, $user->id, 60)),
            'plans' => DB::table('teaching_plans')->where('teacher_id', $user->id)->where('academic_year', $year)->count(),
            'pending' => collect($insights->pending($from, Carbon::parse($today)->subDay()->toDateString(), $user->id))->take(7)->values(),
            'behind' => $rows->present($behind),
        ];
    }

    public function save(Request $request)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'log_date' => ['required_without:id', 'date_format:Y-m-d'],
            'class_id' => ['required_without:id', 'integer', 'exists:classes,id'],
            'subject_id' => ['required_without:id', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['required_without:id', 'integer'],
            'period_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'topic_id' => ['nullable', 'integer'],
            'substitute_id' => ['nullable', 'integer'],
            'held' => ['required', 'boolean'],
            'not_held_reason' => ['nullable', 'string', 'max:120'],
            'coverage' => ['nullable', 'integer', 'between:0,100'],
            'understanding' => ['nullable', 'in:good,average,revision'],
            'homework' => ['nullable', 'boolean'],
            'next_plan' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->log->save($user, $d));
    }

    public function destroy(Request $request, TeachingLog $log)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');

        return response()->json($this->log->delete($user, $log));
    }

    public function copyTo(Request $request, TeachingLog $log)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        $d = $request->validate(['targets' => ['required', 'array', 'min:1', 'max:20'], 'targets.*.class_id' => ['required', 'integer', 'exists:classes,id'], 'targets.*.period_id' => ['nullable', 'integer']]);

        return response()->json(['saved' => $this->log->copyTo($user, $log, $d['targets'])]);
    }
}
