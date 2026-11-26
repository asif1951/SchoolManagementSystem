<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teaching\LessonPlan;
use App\Models\Teaching\SyllabusChapter;
use App\Models\Teaching\TeachingLog;
use App\Models\Teaching\TeachingPlan;
use App\Models\User;
use App\Notifications\TeachingNotice;
use App\Services\RoutineService;
use App\Services\Teaching\Calendar;
use App\Services\Teaching\DailyLog;
use App\Services\Teaching\PlanService;
use App\Services\Teaching\Progress;
use App\Services\Teaching\Scheduler;
use App\Services\Teaching\SyllabusService;
use Database\Seeders\Concerns\BuildsDemoSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Demo data for the WHOLE Teaching Plan module, so that every screen has something to show:
 * holidays, syllabus (this session and a copy for the next), yearly plans at different paces (ahead, on track, behind,
 * far behind), a month of daily logs (not held, substitute, extra classes), lesson plans and templates, review comments,
 * approval states, notifications and a Head Teacher.
 *
 * It builds on the subject teachers the school already has. When there are none, it makes its own "Demo" classes,
 * teachers and routine and leaves the school's own classes alone. Safe to run twice: what exists is left alone.
 *
 *     php artisan db:seed --class=TeachingPlanDemoSeeder
 */
class TeachingPlanDemoSeeder extends Seeder
{
    use BuildsDemoSchool;

    private const MAX_PLANS = 24;

    private const CHAPTERS = [
        'default' => ['Introduction', 'Basic Ideas', 'Core Concepts', 'Methods and Practice', 'Applications', 'Problem Solving', 'Review Project', 'Revision'],
        'Mathematics' => ['Numbers', 'Ratio and Percentage', 'Algebraic Expressions', 'Simple Equations', 'Lines and Angles', 'Triangles', 'Mensuration', 'Statistics'],
        'English' => ['Going to School', 'Our Family', 'Health and Hygiene', 'The Environment', 'Stories We Love', 'Festivals', 'Writing Letters', 'Grammar Revision'],
        'Bangla' => ['গদ্য: প্রথম পাঠ', 'কবিতা: আমার দেশ', 'গদ্য: মুক্তিযুদ্ধ', 'ব্যাকরণ: ভাষা ও বর্ণ', 'কবিতা: প্রকৃতি', 'রচনা', 'পত্র লিখন', 'পুনরালোচনা'],
        'Science' => ['Scientific Method', 'Living Things', 'Cells', 'Matter', 'Force and Motion', 'Light', 'Energy', 'Our Environment'],
    ];

    public function run(): void
    {
        $today = Calendar::today();
        $year = substr($today, 0, 4);

        $this->holidays($year);

        $assignments = $this->assignments($year);

        if ($assignments->isEmpty()) {
            $this->demoSchool($year);
            $assignments = $this->assignments($year);
        }

        $this->syllabus($year, $assignments);
        $head = $this->headTeacher();
        $made = $this->plans($year, $today, $assignments);
        $logs = $this->logs($today, $made);
        app(Progress::class)->recomputeMany(TeachingPlan::whereIn('id', $made->pluck('id'))->get(), $today);
        $lessons = $this->lessons($today, $made);
        $this->reviews($made, $head);

        $this->command?->info("Teaching Plan demo: {$made->count()} plans, {$logs} daily log entries and {$lessons} lesson plans added.");
        $this->command?->line('Approval states are filled in; switch "Yearly plans need approval" on in Teaching Settings to see them.');
    }

    private function holidays(string $year): void
    {
        foreach ([['02-21', '02-21', 'International Mother Language Day'], ['03-26', '03-26', 'Independence Day'], ['04-14', '04-14', 'Pohela Boishakh'],
                  ['05-01', '05-01', 'May Day'], ['06-14', '06-25', 'Summer vacation'], ['12-16', '12-16', 'Victory Day']] as [$a, $b, $title]) {
            CalendarEvent::firstOrCreate(['type' => 'holiday', 'starts_on' => "{$year}-{$a}", 'ends_on' => "{$year}-{$b}"], ['title' => $title, 'off' => true]);
        }
    }

    private function assignments(string $year)
    {
        return DB::table('class_subject_teachers as t')->join('classes as c', 'c.id', '=', 't.class_id')->join('subjects as s', 's.name', '=', 't.subject')
            ->join('users as u', 'u.id', '=', 't.user_id')->whereNull('t.ends_on')->where('c.academic_year', $year)->where('u.status', User::STATUS_ACTIVE)
            // classes that have a routine first, so the daily log has something to show
            ->leftJoin('class_routines as r', 'r.class_id', '=', 'c.id')
            ->orderByRaw('r.id IS NULL')->orderBy('c.id')->orderBy('s.id')->limit(self::MAX_PLANS)
            ->get(['t.class_id', 's.id as subject_id', 's.name as subject', 't.user_id', 'c.class_name']);
    }

    /** an empty school: demo teachers, classes, subject teachers and a published routine */
    private function demoSchool(string $year): void
    {
        $subjects = ['Bangla', 'English', 'Mathematics', 'Science'];
        $classes = $this->demoClasses($year, array_combine($subjects, ['Rahima Begum', 'Kamal Hossain', 'Nasrin Akter', 'Jahid Hasan']));
        $routines = app(RoutineService::class);
        $routines->ensureDefaultPeriods();

        foreach ($classes as $n => $class) {
            if ($routines->grid($class)['filled'] > 0) {
                continue;                                   // already has a routine
            }

            $teachers = $class->currentSubjectTeachers();
            $periods = $routines->periodsForClass($class)->where('is_break', false)->values();

            foreach ($routines->workingDays() as $day) {
                foreach ($periods->take(4) as $k => $period) {
                    // each section starts the day with a different subject, so no teacher is needed twice at once
                    $i = ($k + $n + $day) % count($subjects);

                    try {
                        $routines->setCell($class, $day, $period->id, $subjects[$i], null, $teachers->get($subjects[$i])?->user_id);
                    } catch (ValidationException) {
                        // the teacher is busy with a routine that was there before: leave the period empty
                    }
                }
            }

            if ($routines->grid($class)['filled'] > 0) {
                try {
                    $routines->publish($class, User::find($teachers->first()->user_id));
                } catch (ValidationException) {
                    // stays a draft; the daily log reads the draft then
                }
            }
        }

    }

    private function syllabus(string $year, $assignments): void
    {
        $syllabus = app(SyllabusService::class);

        foreach ($assignments->unique(fn ($a) => "{$a->class_name}|{$a->subject_id}") as $n => $a) {
            if (! SyllabusChapter::where(['academic_year' => $year, 'class_name' => $a->class_name, 'subject_id' => $a->subject_id])->exists()) {
                $names = collect(self::CHAPTERS)->first(fn ($v, $k) => Str::contains($a->subject, $k)) ?? self::CHAPTERS['default'];
                $text = collect($names)->map(fn ($c, $i) => "{$c} | " . (10 + ($i % 3) * 2) . ($i < 5 ? "\n- {$c}: part one\n- {$c}: part two\n- {$c}: exercises" : ''))->implode("\n");
                $syllabus->addLines($year, $a->class_name, $a->subject_id, $text);
            }
        }

        // next session already prepared for the first subject
        $first = $assignments->first();
        $syllabus->copy($year, $first->class_name, $first->subject_id, (string) ((int) $year + 1), [$first->class_name]);
    }

    private function headTeacher(): User
    {
        if ($head = User::where('role', 'head_teacher')->first()) {
            return $head;
        }

        $password = Str::password(12, symbols: false);
        $head = User::create(['name' => 'Demo Head Teacher', 'email' => 'headteacher@school.test', 'password' => $password, 'role' => 'head_teacher', 'status' => User::STATUS_ACTIVE]);
        $this->command?->warn("Head Teacher: headteacher@school.test / {$password}");

        return $head;
    }

    /** one plan per assignment that has none, each teacher at a different pace */
    private function plans(string $year, string $today, $assignments)
    {
        $made = collect();

        foreach ($assignments->values() as $n => $a) {
            if (TeachingPlan::where(['class_id' => $a->class_id, 'subject_id' => $a->subject_id, 'teacher_id' => $a->user_id])->exists()) {
                continue;
            }

            $plan = app(PlanService::class)->create(SchoolClass::find($a->class_id), Subject::find($a->subject_id), User::find($a->user_id));
            app(Scheduler::class)->auto($plan);

            // compared with the calendar: ahead, on track, a little behind, far behind
            $pace = [1.1, 1.0, 0.9, 0.7][$n % 4];
            $items = $plan->items()->get();
            $target = min(1, $plan->fresh()->expected_pct / 100 * $pace) * $items->sum('planned_periods');
            $sum = 0;

            foreach ($items as $item) {
                if ($sum + $item->planned_periods > $target) {
                    break;
                }

                $sum += $item->planned_periods;
                $item->update(['completed_on' => min($item->ends_on->toDateString(), $today)]);
            }

            $made->push($plan);
        }

        return $made;
    }

    /** a month of daily logs: from the routine where there is one, otherwise extra classes twice a week */
    private function logs(string $today, $made): int
    {
        $daily = app(DailyLog::class);
        $calendar = app(Calendar::class);
        $days = $calendar->teachingDays(Carbon::parse($today)->subDays(30)->toDateString(), Carbon::parse($today)->subDay()->toDateString());
        $teachers = User::where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE)->orderBy('id')->pluck('id');
        $reasons = ['School event', 'Teacher absent', 'Exam', 'Holiday'];
        $count = 0;

        foreach ($made->values() as $k => $plan) {
            $items = $plan->items()->with('chapter.topics')->get();
            $current = $items->firstWhere('completed_on', null) ?? $items->last();
            $topics = $current?->chapter->topics->values() ?? collect();
            $inRoutine = false;
            $entries = [];

            foreach ($days as $date) {
                foreach ($daily->routineSlots($plan->teacher_id, $date)->where('class_id', $plan->class_id)->where('subject_id', $plan->subject_id) as $slot) {
                    $inRoutine = true;
                    $entries[] = [$date, $slot['period_id']];
                }
            }

            if (! $inRoutine) {
                foreach ($days as $i => $date) {
                    $i % 3 === 0 && $entries[] = [$date, null];
                }
            }

            foreach ($entries as $n => [$date, $periodId]) {
                // some teachers forget now and then
                if (($n + $k) % 9 === 8) {
                    continue;
                }

                $held = ($n + $k) % 11 !== 5;
                $substitute = $held && ($n + $k) % 13 === 4 ? $teachers->first(fn ($id) => $id !== $plan->teacher_id) : null;
                $coverage = $held ? [100, 100, 50, 100, 0][($n + $k) % 5] : 0;

                $log = $this->log($plan, $date, $periodId, [
                    'item_id' => $current?->id, 'topic_id' => $topics->count() ? $topics[$n % $topics->count()]->id : null,
                    'substitute_id' => $substitute, 'held' => $held, 'not_held_reason' => $held ? null : $reasons[($n + $k) % 4], 'coverage' => $coverage,
                    'understanding' => $held ? ['good', 'good', 'average', 'good', 'revision'][($n + $k) % 5] : null, 'homework' => $held && $n % 2 === 0,
                    'next_plan' => $held && $n % 4 === 0 ? 'Practise the exercises' : null, 'note' => $held && $n % 10 === 3 ? 'Students asked for one more example.' : null,
                ]);
                $count += $log ? 1 : 0;
            }

            // one extra class for every fourth plan
            if ($k % 4 === 0 && $days) {
                $count += $this->log($plan, $days[intdiv(count($days), 2)], null, ['item_id' => $current?->id, 'held' => true, 'coverage' => 100, 'understanding' => 'good', 'note' => 'Extra class before the test']) ? 1 : 0;
            }
        }

        return $count;
    }

    /** one log; a routine period already logged is left alone */
    private function log(TeachingPlan $plan, string $date, ?int $periodId, array $data): bool
    {
        if ($periodId && TeachingLog::where(['log_date' => $date, 'class_id' => $plan->class_id, 'period_id' => $periodId])->exists()) {
            return false;
        }

        TeachingLog::create($data + ['log_date' => $date, 'class_id' => $plan->class_id, 'subject_id' => $plan->subject_id, 'teacher_id' => $plan->teacher_id,
            'period_id' => $periodId, 'kind' => $periodId ? 'regular' : 'extra', 'plan_id' => $plan->id, 'created_by' => $plan->teacher_id]);

        return true;
    }

    private function lessons(string $today, $made): int
    {
        $count = 0;

        foreach ($made->take(8)->values() as $k => $plan) {
            $item = $plan->items()->with('chapter.topics')->get()->first(fn ($i) => $i->chapter->topics->isNotEmpty());

            if (! $item) {
                continue;
            }

            foreach ($item->chapter->topics->take(2)->values() as $n => $topic) {
                $lesson = LessonPlan::firstOrCreate(['owner_id' => $plan->teacher_id, 'class_id' => $plan->class_id, 'subject_id' => $plan->subject_id, 'topic_id' => $topic->id, 'is_template' => false], [
                    'chapter_id' => $item->chapter_id, 'title' => $topic->name, 'duration' => 40, 'lesson_date' => Carbon::parse($today)->addDays($n + 1)->toDateString(),
                    'objectives' => "Students understand the main idea of {$topic->name}.", 'outcomes' => 'Students can explain it with an example.',
                    'method' => 'Short explanation, questions, group practice.', 'materials' => 'Textbook, board, work sheet.', 'assessment' => 'Three oral questions at the end.',
                    'homework' => 'Exercise questions 1 to 5.',
                ]);
                $count += $lesson->wasRecentlyCreated ? 1 : 0;
            }

            if ($k < 2) {
                $template = LessonPlan::firstOrCreate(['owner_id' => $plan->teacher_id, 'subject_id' => $plan->subject_id, 'is_template' => true, 'title' => $k ? 'Revision class' : 'New topic class'], [
                    'duration' => 40, 'objectives' => 'State what students should learn.', 'method' => '5 min recap, 20 min teaching, 10 min practice, 5 min questions.', 'assessment' => 'Exit questions.',
                ]);
                $count += $template->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $count;
    }

    /** review comments with their notifications, and plans in each approval state */
    private function reviews($made, User $head): void
    {
        foreach ($made->values() as $n => $plan) {
            [$status, $comment] = match ($n % 5) {
                0 => ['approved', 'Good plan. Please keep to the dates.'],
                1 => ['submitted', null],
                2 => ['returned', 'Leave more time for revision before the annual exam.'],
                3 => ['draft', 'You are behind the plan. Arrange an extra class this week.'],
                default => ['draft', null],
            };

            DB::table('teaching_plans')->where('id', $plan->id)->update([
                'status' => $status, 'submitted_at' => $status === 'draft' ? null : now()->subDays(10),
                'approved_by' => $status === 'approved' ? $head->id : null, 'approved_at' => $status === 'approved' ? now()->subDays(8) : null,
            ]);

            if (! $comment) {
                continue;
            }

            $kind = in_array($status, ['approved', 'returned'], true) ? $status : 'comment';
            $plan->reviews()->create(['user_id' => $head->id, 'kind' => $kind, 'body' => $comment]);
            User::find($plan->teacher_id)?->notify(new TeachingNotice($kind === 'comment' ? 'notify.review' : "notify.{$kind}", ['name' => $head->name], "/teaching/plans/{$plan->id}"));
        }
    }
}
