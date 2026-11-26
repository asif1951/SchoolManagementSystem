<?php

namespace Tests\Feature;

use App\Models\ClassRoutine;
use App\Models\ClassRoutineSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\RoutineService;
use Database\Seeders\RoutineDemoSeeder;
use Database\Seeders\TeachingPlanDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class RoutineSeederTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 08:00:00');
    }

    public function test_on_an_empty_school_it_builds_clash_free_routines(): void
    {
        $this->makeUser(User::ROLE_ADMIN);
        $this->seed(RoutineDemoSeeder::class);

        $svc = app(RoutineService::class);
        $this->assertSame(3, SchoolClass::where('class_name', 'like', 'Demo%')->count());
        $this->assertGreaterThan(80, ClassRoutineSlot::count());
        $this->assertSame(3, ClassRoutine::whereNotNull('published_at')->count());
        $this->assertSame([], $svc->teacherClashes());
        $this->assertSame([], $svc->teacherClashes(50, true));

        foreach (SchoolClass::all() as $c) {
            $this->assertSame([], $svc->grid($c)['plan_check']['over']);
        }

        $before = ClassRoutineSlot::orderBy('id')->pluck('id')->all();
        $this->seed(RoutineDemoSeeder::class);
        $this->assertSame($before, ClassRoutineSlot::orderBy('id')->pluck('id')->all());
    }

    public function test_a_routine_the_school_made_is_never_touched(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $mine = $this->makeClass('Class 8', 'A', 2026, ['subjects' => ['Math']]);
        $mine->assignSubjectTeacher('Math', $teacher);
        $empty = $this->makeClass('Class 8', 'B', 2026, ['subjects' => ['Math']]);
        $empty->assignSubjectTeacher('Math', $teacher);
        $svc = app(RoutineService::class);
        $svc->ensureDefaultPeriods();
        $svc->setCell($mine, 0, $svc->periodsForClass($mine)->first()->id, 'Math', null, $teacher->id);

        $this->seed(RoutineDemoSeeder::class);

        $this->assertSame(1, $svc->grid($mine)['filled']);
        $this->assertGreaterThan(0, $svc->grid($empty)['filled']);
        $this->assertSame(0, SchoolClass::where('class_name', 'like', 'Demo%')->count());
        $this->assertSame([], $svc->teacherClashes());
    }

    public function test_both_demo_seeders_work_together_in_either_order(): void
    {
        $this->seed(RoutineDemoSeeder::class);
        $this->seed(TeachingPlanDemoSeeder::class);

        $this->assertSame(3, SchoolClass::where('class_name', 'like', 'Demo%')->count());
        $this->assertSame([], app(RoutineService::class)->teacherClashes());
    }
}
