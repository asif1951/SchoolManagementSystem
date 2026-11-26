<?php

namespace App\Http\Controllers\Teaching;

use App\Services\Teaching\Access;
use App\Services\Teaching\Calendar;
use App\Services\Teaching\Insights;
use App\Services\Teaching\Progress;
use App\Services\Teaching\Rows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends TeachingController
{
    /** The school overview. A teacher's home is the day itself, so they are taken to Today. */
    public function index(Request $request, Rows $rows, Insights $insights, Progress $progress)
    {
        $user = $this->need($request);

        if (! Access::seesAll($user)) {
            return redirect()->route('teaching.log');
        }

        $years = $this->years();
        $year = $this->year($request, $years);
        $today = Calendar::today();
        $progress->ensureFresh($year, $today);
        $pendingToday = $insights->pending($today, $today);
        $names = $insights->names(array_column($pendingToday, 'teacher_id'));

        return Inertia::render('Teaching/Dashboard', [
            'years' => $years, 'session' => $year, 'today' => $today, 'unread' => $this->notices($user), 'flash' => $this->flash(),
            'setup' => $this->setup($user, $year),
            'summary' => $insights->summary($year),
            'behind' => $rows->present($rows->plans($year, [])->whereIn('p.health', ['red', 'amber'])->orderBy('p.gap')->limit(8)->get()->all()),
            'notLogged' => collect($pendingToday)->map(fn ($p) => $p + ['name' => $names[$p['teacher_id']] ?? '?'])->sortByDesc('missing')->values()->take(40),
            'notLoggedCount' => count($pendingToday),
            'groups' => $insights->completion($year, 'class'),
        ]);
    }

    /**
     * The steps that make the module work, in order, each with whether it is done and where to do it.
     * A step the person has no permission for is shown without a link.
     */
    private function setup($user, string $year): array
    {
        $classes = DB::table('classes')->where('academic_year', $year)->select('id');
        $step = fn (string $key, bool $done, string $url, bool $may) => ['key' => $key, 'done' => $done, 'url' => $may ? $url : null];

        return [
            $step('teachers', DB::table('class_subject_teachers')->whereNull('ends_on')->whereIn('class_id', $classes)->exists(), "/subject-teachers?year={$year}", $user->canDo('classes.manage')),
            $step('routine', DB::table('class_routine_slots as s')->join('class_routines as r', 'r.id', '=', 's.class_routine_id')->whereIn('r.class_id', $classes)->exists(), '/routine', $user->canDo('routine.manage')),
            $step('holidays', DB::table('calendar_events')->where('off', true)->where(fn ($q) => $q->where('ends_on', '>=', substr($year, 0, 4) . '-01-01')->orWhere('repeat', '!=', 'none'))->exists(), '/calendar', $user->canDo('calendar.manage')),
            $step('syllabus', DB::table('syllabus_chapters')->whereNull('deleted_at')->where('academic_year', '<=', $year)->exists(), '/teaching/syllabus', $user->canDo('teaching.syllabus')),
            $step('plans', DB::table('teaching_plans')->where('academic_year', $year)->exists(), '/teaching/plans', true),
            $step('logs', DB::table('teaching_logs')->where('log_date', '>=', substr($year, 0, 4) . '-01-01')->exists(), '/teaching/log', true),
        ];
    }

    /** mark one notice (or all of mine) as read */
    public function read(Request $request)
    {
        $user = $this->need($request);
        $d = $request->validate(['id' => ['nullable', 'uuid']]);
        $user->unreadNotifications()->when($d['id'] ?? null, fn ($q, $id) => $q->where('id', $id))->update(['read_at' => now()]);

        return back();
    }
}
