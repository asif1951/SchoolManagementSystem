<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\CalendarEvent;
use App\Models\Teaching\LessonPlan;
use App\Models\Teaching\SyllabusChapter;
use App\Models\Teaching\TeachingLog;
use App\Models\Teaching\TeachingPlan;
use App\Models\Teaching\TeachingReview;
use App\Models\User;
use App\Services\RoutineService;
use Database\Seeders\TeachingPlanDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeachingSeederTest extends TestCase
{
    use RefreshDatabase;

    private function counts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['users', 'classes', 'syllabus_chapters', 'teaching_plans', 'teaching_logs', 'lesson_plans', 'teaching_reviews', 'notifications', 'calendar_events', 'class_routine_slots']);
    }

    public function test_on_an_empty_school_it_fills_every_part_of_the_module(): void
    {
        Carbon::setTestNow('2026-09-15 08:00:00');

        $this->seed(TeachingPlanDemoSeeder::class);

        $this->assertSame(3, SchoolClass::where('class_name', 'like', 'Demo%')->count());
        $this->assertSame(12, TeachingPlan::count());
        $this->assertGreaterThanOrEqual(6, CalendarEvent::where('type', 'holiday')->count());
        $this->assertTrue(SyllabusChapter::where('academic_year', '2027')->exists());
        $this->assertTrue(User::where('role', 'head_teacher')->exists());

        // the daily log: ordinary, not held, substitute and extra classes
        $this->assertGreaterThan(100, TeachingLog::count());
        $this->assertTrue(TeachingLog::whereNotNull('period_id')->exists());
        $this->assertTrue(TeachingLog::where('held', false)->whereNotNull('not_held_reason')->exists());
        $this->assertTrue(TeachingLog::whereNotNull('substitute_id')->exists());
        $this->assertTrue(TeachingLog::where('kind', 'extra')->exists());

        // plans differ: some on track, some behind, and every approval state
        $this->assertTrue(TeachingPlan::where('health', 'green')->exists());
        $this->assertTrue(TeachingPlan::whereIn('health', ['amber', 'red'])->exists());
        $this->assertEqualsCanonicalizing(['approved', 'draft', 'returned', 'submitted'], TeachingPlan::distinct()->pluck('status')->all());

        $this->assertTrue(LessonPlan::where('is_template', true)->exists());
        $this->assertTrue(LessonPlan::where('is_template', false)->exists());
        $this->assertGreaterThan(0, TeachingReview::count());
        $this->assertGreaterThan(0, DB::table('notifications')->count());
        $this->assertSame([], app(RoutineService::class)->teacherClashes());

        // a second run changes nothing
        $before = $this->counts();
        $this->seed(TeachingPlanDemoSeeder::class);
        $this->assertSame($before, $this->counts());
    }
}
