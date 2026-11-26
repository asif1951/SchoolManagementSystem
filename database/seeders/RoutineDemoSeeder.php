<?php

namespace Database\Seeders;

use App\Models\ClassRoutineSlot;
use App\Models\RoutinePlan;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\RoutineGenerator;
use App\Services\RoutineService;
use App\Services\Teaching\Calendar;
use Database\Seeders\Concerns\BuildsDemoSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demo data for the Routine module: periods and breaks, a weekly plan for each class, routines built by the automatic
 * builder (so no teacher is ever in two classes at once), most of them published and some left as drafts.
 *
 * Only classes whose routine is EMPTY are filled: routines made by the school are never touched. When the school has no
 * subject teachers at all, three "Demo" classes with demo teachers are created. Safe to run twice.
 *
 *     php artisan db:seed --class=RoutineDemoSeeder
 */
class RoutineDemoSeeder extends Seeder
{
    use BuildsDemoSchool;

    private const MAX_CLASSES = 30;

    private const STAFF = ['Bangla' => 'Rahima Begum', 'English' => 'Kamal Hossain', 'Mathematics' => 'Nasrin Akter',
        'Science' => 'Jahid Hasan', 'ICT' => 'Shirin Sultana', 'Religion' => 'Abdul Karim'];

    public function run(RoutineService $routines, RoutineGenerator $generator): void
    {
        $year = substr(Calendar::today(), 0, 4);
        $routines->ensureDefaultPeriods();

        $assigned = fn () => SchoolClass::where('academic_year', $year)->where('status', 'active')
            ->whereIn('id', DB::table('class_subject_teachers')->whereNull('ends_on')->whereNotNull('user_id')->select('class_id'));

        if (! $assigned()->exists()) {
            $this->demoClasses($year, self::STAFF);
        }

        $classes = $assigned()
            ->whereNotIn('id', ClassRoutineSlot::query()->join('class_routines as r', 'r.id', '=', 'class_routine_slots.class_routine_id')->select('r.class_id'))
            ->orderBy('class_name')->orderBy('section')->limit(self::MAX_CLASSES)->get();

        if ($classes->isEmpty()) {
            $this->command?->info("Routine demo: every class of {$year} that has subject teachers already has a routine. Nothing was changed.");

            return;
        }

        // the weekly plan the builder follows: kept when the school has made one, else the week shared out evenly
        foreach ($classes as $class) {
            if (! RoutinePlan::where('class_id', $class->id)->exists()) {
                $routines->savePlan($class, $routines->planFor($class)['rows']);
            }
        }

        $result = $generator->run($classes, ['seed' => 20260101, 'keep_existing' => false, 'seconds' => 30.0]);
        $applied = $generator->apply($result, $classes, false);

        $publisher = User::where('role', User::ROLE_ADMIN)->orderBy('id')->first() ?? User::where('role', User::ROLE_TEACHER)->orderBy('id')->first();
        $published = 0;

        foreach ($classes->values() as $n => $class) {
            // every fourth routine stays a draft, so both states can be seen
            if ($n % 4 === 3 || ! $publisher) {
                continue;
            }

            try {
                $routines->publish($class, $publisher);
                $published++;
            } catch (ValidationException) {
                // empty, or it would double-book against a routine the school already has: it stays a draft
            }
        }

        $this->command?->info("Routine demo: {$applied['classes']} routines made ({$applied['applied']} periods), {$published} published.");

        $short = collect($result['classes'])->filter(fn ($r) => ! empty($r['error']) || ! empty($r['missing']))->count();
        $short && $this->command?->warn("{$short} " . ($short === 1 ? 'class has' : 'classes have') . ' periods the builder could not place (usually a teacher already busy). Open them to see.');
    }
}
