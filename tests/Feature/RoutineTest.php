<?php

namespace Tests\Feature;

use App\Models\ClassRoutine;
use App\Models\ClassRoutineSlot;
use App\Models\Role;
use App\Models\RoutinePeriod;
use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\User;
use App\Services\RoutineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class RoutineTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private User $rahim;      // teaches Math in 6A and 6B
    private User $karim;      // teaches English in 6A
    private SchoolClass $a;
    private SchoolClass $b;
    private RoutineService $svc;
    private RoutinePeriod $p1;
    private RoutinePeriod $p2;
    private RoutinePeriod $brk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(RoutineService::class);
        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->rahim = $this->makeUser(User::ROLE_TEACHER);
        $this->karim = $this->makeUser(User::ROLE_TEACHER);

        $this->a = $this->makeClass('Class 6', 'A', null, ['subjects' => ['Math', 'English']], $this->karim);
        $this->b = $this->makeClass('Class 6', 'B', null, ['subjects' => ['Math', 'English']], $this->rahim);

        $this->a->assignSubjectTeacher('Math', $this->rahim);
        $this->a->assignSubjectTeacher('English', $this->karim);
        $this->b->assignSubjectTeacher('Math', $this->rahim);
        $this->b->assignSubjectTeacher('English', $this->rahim);       // sections can have different teachers

        $this->p1 = RoutinePeriod::create(['name' => '1st', 'starts_at' => '10:00', 'ends_at' => '10:40', 'sort_order' => 1]);
        $this->p2 = RoutinePeriod::create(['name' => '2nd', 'starts_at' => '10:40', 'ends_at' => '11:20', 'sort_order' => 2]);
        $this->brk = RoutinePeriod::create(['name' => 'Break', 'starts_at' => '11:20', 'ends_at' => '11:50', 'is_break' => true, 'sort_order' => 3]);
    }

    private function cellOf(SchoolClass $c, int $day, RoutinePeriod $p, ?string $subject, ?int $teacher = null, ?string $label = null): void
    {
        $this->svc->setCell($c, $day, $p->id, $subject, $label, $teacher);
    }

    public function test_the_working_days_default_to_sunday_to_thursday_in_week_order(): void
    {
        $this->assertSame([0, 1, 2, 3, 4], $this->svc->workingDays());

        $this->svc->setWorkingDays([6, 0, 1]);
        $this->assertSame([6, 0, 1], $this->svc->workingDays());          // Saturday first

        $this->expectException(ValidationException::class);
        $this->svc->setWorkingDays([]);
    }

    public function test_a_cell_takes_a_class_subject_and_its_teacher(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);

        $cell = $this->svc->grid($this->a)['cells']["0-{$this->p1->id}"];
        $this->assertSame('Math', $cell['subject']);
        $this->assertSame($this->rahim->name, $cell['teacher']);
    }

    public function test_the_class_module_supplies_the_subjects_and_their_teachers(): void
    {
        $grid = $this->svc->grid($this->a);
        $byName = collect($grid['subjects'])->keyBy('name');

        $this->assertSame($this->rahim->id, $byName['Math']['teacher_id']);
        $this->assertSame($this->karim->id, $byName['English']['teacher_id']);
        $this->assertSame($this->karim->name, $grid['class']['class_teacher']);
        $this->assertSame($this->rahim->id, collect($this->svc->grid($this->b)['subjects'])->keyBy('name')['English']['teacher_id']);   // section B differs
    }

    public function test_only_the_classes_own_subjects_real_periods_and_working_days_are_accepted(): void
    {
        foreach ([
            fn () => $this->cellOf($this->a, 0, $this->p1, 'Physics'),                    // not a subject of this class
            fn () => $this->cellOf($this->a, 0, $this->brk, 'Math'),                      // a break
            fn () => $this->cellOf($this->a, 5, $this->p1, 'Math'),                       // Friday is not a working day
            fn () => $this->cellOf($this->a, 0, $this->p1, 'Math', $this->admin->id),     // not a teacher
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a refusal');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, ClassRoutineSlot::count());
    }

    public function test_a_teacher_cannot_be_in_two_classes_at_the_same_time(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);

        try {
            $this->cellOf($this->b, 0, $this->p1, 'Math', $this->rahim->id);
            $this->fail('the clash should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($this->rahim->name, $e->errors()['teacher_id'][0]);
            $this->assertStringContainsString('Sunday', $e->errors()['teacher_id'][0]);
        }

        // another period, or another day, is fine
        $this->cellOf($this->b, 0, $this->p2, 'Math', $this->rahim->id);
        $this->cellOf($this->b, 1, $this->p1, 'Math', $this->rahim->id);
        $this->assertSame(3, ClassRoutineSlot::count());
    }

    public function test_the_clash_is_found_across_shifts_by_clock_time(): void
    {
        $morning = Shift::firstOrCreate(['name' => 'Morning'], ['sort_order' => 1, 'is_active' => true]);
        $day = Shift::firstOrCreate(['name' => 'Day'], ['sort_order' => 2, 'is_active' => true]);
        $this->a->update(['shift_id' => $morning->id]);
        $this->b->update(['shift_id' => $day->id]);

        // the day shift has its own bell: its 1st period overlaps the morning shift's 2nd
        $d1 = RoutinePeriod::create(['shift_id' => $day->id, 'name' => '1st', 'starts_at' => '10:20', 'ends_at' => '11:00', 'sort_order' => 1]);
        $m2 = RoutinePeriod::create(['shift_id' => $morning->id, 'name' => '2nd', 'starts_at' => '10:40', 'ends_at' => '11:20', 'sort_order' => 1]);

        $this->svc->setCell($this->a->fresh(), 0, $m2->id, 'Math', null, $this->rahim->id);

        $this->expectException(ValidationException::class);
        $this->svc->setCell($this->b->fresh(), 0, $d1->id, 'Math', null, $this->rahim->id);
    }

    public function test_clearing_a_cell_and_replacing_it(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);
        $this->cellOf($this->a, 0, $this->p1, 'English', $this->karim->id);          // replaces
        $this->assertSame(1, ClassRoutineSlot::count());
        $this->assertSame('English', $this->svc->grid($this->a)['cells']["0-{$this->p1->id}"]['subject']);

        $this->cellOf($this->a, 0, $this->p1, null);                                  // empties
        $this->assertSame(0, ClassRoutineSlot::count());
    }

    public function test_a_period_that_is_not_a_subject_can_have_a_label(): void
    {
        $this->cellOf($this->a, 0, $this->p1, null, null, 'Games');

        $cell = $this->svc->grid($this->a)['cells']["0-{$this->p1->id}"];
        $this->assertSame('Games', $cell['subject']);
        $this->assertFalse($cell['is_subject']);
    }

    public function test_copying_a_day_keeps_the_teachers_free_and_reports_what_it_skipped(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);
        $this->cellOf($this->a, 0, $this->p2, 'English', $this->karim->id);
        $this->cellOf($this->b, 1, $this->p1, 'Math', $this->rahim->id);               // rahim is busy in 6B on Monday, 1st

        $r = $this->svc->copyDay($this->a, 0, [1, 2]);

        $this->assertSame(['Math', 'English'], [$this->svc->grid($this->a)['cells']["2-{$this->p1->id}"]['subject'], $this->svc->grid($this->a)['cells']["2-{$this->p2->id}"]['subject']]);
        $this->assertArrayNotHasKey("1-{$this->p1->id}", $this->svc->grid($this->a)['cells']);   // Monday 1st skipped: clash
        $this->assertCount(1, $r['skipped']);
        $this->assertSame(3, $r['copied']);
    }

    public function test_copying_from_a_sister_section_uses_this_sections_own_subject_teachers(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'English', $this->karim->id);
        $this->cellOf($this->a, 0, $this->p2, 'Math', $this->rahim->id);

        $r = $this->svc->copyFromClass($this->b, $this->a);

        $cells = $this->svc->grid($this->b)['cells'];
        $this->assertSame($this->rahim->id, $cells["0-{$this->p1->id}"]['teacher_id']);      // 6B's English teacher is rahim, not karim
        $this->assertSame('English', $cells["0-{$this->p1->id}"]['subject']);
    }

    public function test_publishing_freezes_a_copy_and_later_edits_wait_for_the_next_publish(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);
        $this->assertFalse($this->svc->grid($this->a)['published']);

        $this->svc->publish($this->a, $this->admin);
        $g = $this->svc->grid($this->a);
        $this->assertTrue($g['published']);
        $this->assertFalse($g['changed']);

        $this->cellOf($this->a, 0, $this->p2, 'English', $this->karim->id);             // an edit after publishing
        $this->assertTrue($this->svc->grid($this->a)['changed']);
        $this->assertCount(1, $this->svc->grid($this->a, true)['cells']);             // what people see is unchanged
        $this->assertCount(2, $this->svc->grid($this->a)['cells']);

        $this->svc->publish($this->a, $this->admin);
        $this->assertCount(2, $this->svc->grid($this->a, true)['cells']);
        $this->assertFalse($this->svc->grid($this->a)['changed']);

        $this->svc->unpublish($this->a);
        $this->assertFalse($this->svc->grid($this->a)['published']);
        $this->assertCount(2, $this->svc->grid($this->a)['cells']);                   // the draft is kept
    }

    public function test_an_empty_routine_cannot_be_published(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc->publish($this->a, $this->admin);
    }

    public function test_the_teachers_week_is_collected_across_classes(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);
        $this->cellOf($this->b, 0, $this->p2, 'Math', $this->rahim->id);
        $this->svc->publish($this->a, $this->admin);

        $draft = $this->svc->teacherGrid($this->rahim->id, false);
        $this->assertSame(2, $draft['periods_per_week']);
        $this->assertCount(2, $draft['columns']);

        $published = $this->svc->teacherGrid($this->rahim->id, true);          // only 6A is published
        $this->assertSame(1, $published['periods_per_week']);
        $this->assertSame('Class 6 A', array_values($published['cells'])[0][0]['class']);
    }

    public function test_the_periods_can_be_saved_validated_and_reordered(): void
    {
        $this->svc->savePeriods(null, [
            ['name' => '1st', 'starts_at' => '09:00', 'ends_at' => '09:45'],
            ['name' => 'Break', 'starts_at' => '09:45', 'ends_at' => '10:00', 'is_break' => true],
            ['name' => '2nd', 'starts_at' => '10:00', 'ends_at' => '10:45'],
        ]);

        $set = RoutinePeriod::whereNull('shift_id')->orderBy('sort_order')->get();
        $this->assertSame(['1st', 'Break', '2nd'], $set->pluck('name')->all());
        $this->assertTrue($set[1]->is_break);

        foreach ([
            [['name' => 'X', 'starts_at' => '10:00', 'ends_at' => '09:00']],                                                                             // ends before it starts
            [['name' => 'A', 'starts_at' => '09:00', 'ends_at' => '10:00'], ['name' => 'B', 'starts_at' => '09:30', 'ends_at' => '10:30']],            // overlap
            [['name' => '', 'starts_at' => '09:00', 'ends_at' => '10:00']],                                                                              // no name
        ] as $bad) {
            try {
                $this->svc->savePeriods(null, $bad);
                $this->fail('expected a refusal');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_editing_periods_keeps_the_subjects_placed_in_them(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);

        $this->svc->savePeriods(null, [
            ['name' => '1st', 'starts_at' => '09:00', 'ends_at' => '09:45'],      // the same period, new time
            ['name' => '2nd', 'starts_at' => '09:45', 'ends_at' => '10:30'],
        ]);

        $this->assertSame(1, ClassRoutineSlot::count());                          // still there
        $this->assertSame(2, RoutinePeriod::whereNull('shift_id')->count());
    }

    // ---- the screens --------------------------------------------------------------------------------------------

    public function test_only_those_who_manage_can_open_the_editor_and_change_cells(): void
    {
        $teacher = $this->rahim;

        $this->actingAs($teacher)->get(route('routine.editor', $this->a))->assertForbidden();
        $this->actingAs($teacher)->put(route('routine.cell', $this->a), ['day' => 0, 'period_id' => $this->p1->id, 'subject' => 'Math'])->assertForbidden();
        $this->actingAs($teacher)->post(route('routine.publish', $this->a))->assertForbidden();
        $this->actingAs($teacher)->get(route('routine.periods'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('routine.editor', $this->a))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Routine/Editor')->has('grid.periods')->has('teachers')->where('grid.class.class_teacher', $this->karim->name));
    }

    public function test_the_editor_saves_a_cell_and_shows_a_clash_as_a_message(): void
    {
        $url = route('routine.cell', $this->a);

        $this->actingAs($this->admin)->put($url, ['day' => 0, 'period_id' => $this->p1->id, 'subject' => 'Math', 'teacher_id' => $this->rahim->id])->assertSessionHasNoErrors();
        $this->assertSame(1, ClassRoutineSlot::count());

        $this->actingAs($this->admin)->put(route('routine.cell', $this->b), ['day' => 0, 'period_id' => $this->p1->id, 'subject' => 'Math', 'teacher_id' => $this->rahim->id])
            ->assertSessionHasErrors('teacher_id');
        $this->assertSame(1, ClassRoutineSlot::count());
    }

    public function test_the_list_shows_every_class_to_the_admin_and_only_published_ones_to_a_teacher(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);
        $this->cellOf($this->b, 0, $this->p2, 'Math', $this->rahim->id);
        $this->svc->publish($this->a, $this->admin);

        $this->actingAs($this->admin)->get(route('routine.index', ['session' => (string) $this->a->academic_year]))
            ->assertInertia(fn (Assert $p) => $p->component('Routine/Index')->has('rows', 2)->where('can.manage', true));

        $this->actingAs($this->rahim)->get(route('routine.index', ['session' => (string) $this->a->academic_year]))
            ->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.status', 'published')->where('can.manage', false));
    }

    public function test_a_teacher_prints_only_published_routines_and_only_their_own_timetable(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);
        $this->cellOf($this->b, 0, $this->p2, 'Math', $this->rahim->id);
        $this->svc->publish($this->a, $this->admin);

        $this->actingAs($this->rahim)->get(route('routine.print', $this->a))->assertOk()->assertSee('Class Routine')->assertSee('Math');
        $this->actingAs($this->rahim)->get(route('routine.print', $this->b))->assertNotFound();                           // not published
        $this->actingAs($this->rahim)->get(route('routine.teachers.print', $this->rahim))->assertOk();
        $this->actingAs($this->rahim)->get(route('routine.teachers.print', $this->karim))->assertForbidden();

        // a teacher's own page shows only them, whatever is asked for
        $this->actingAs($this->rahim)->get(route('routine.teachers', ['teacher' => $this->karim->id]))
            ->assertInertia(fn (Assert $p) => $p->where('teacherId', $this->rahim->id)->where('grid.periods_per_week', 1));
    }

    public function test_the_admin_can_print_the_draft_and_the_print_is_marked_as_one(): void
    {
        $this->cellOf($this->a, 0, $this->p1, 'Math', $this->rahim->id);

        $this->actingAs($this->admin)->get(route('routine.print', ['class' => $this->a->id, 'source' => 'draft']))
            ->assertOk()->assertSee('DRAFT')->assertSee('Math')->assertSee($this->a->full_label);

        $this->actingAs($this->admin)->get(route('routine.print', ['class' => $this->a->id, 'lang' => 'bn']))->assertOk()->assertSee('ক্লাস রুটিন');
    }

    public function test_the_periods_page_and_saving_them(): void
    {
        $this->actingAs($this->admin)->get(route('routine.periods'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Routine/Periods')->has('sets')->has('days')->where('working', [0, 1, 2, 3, 4]));

        $this->actingAs($this->admin)->put(route('routine.periods.save'), ['periods' => [['name' => '1st', 'starts_at' => '08:00', 'ends_at' => '08:40']]])->assertSessionHasNoErrors();
        $this->assertSame(1, RoutinePeriod::whereNull('shift_id')->count());

        $this->actingAs($this->admin)->put(route('routine.days.save'), ['days' => [6, 0, 1, 2, 3]])->assertSessionHasNoErrors();
        $this->assertSame([6, 0, 1, 2, 3], $this->svc->workingDays());
    }

    public function test_default_periods_are_created_for_a_new_school(): void
    {
        RoutinePeriod::query()->delete();

        $this->actingAs($this->admin)->get(route('routine.index'))->assertOk();

        $set = RoutinePeriod::whereNull('shift_id')->orderBy('sort_order')->get();
        $this->assertGreaterThanOrEqual(6, $set->count());
        $this->assertTrue($set->contains('is_break', true));
    }

    public function test_a_custom_role_can_be_allowed_to_manage_routines(): void
    {
        Role::create(['slug' => 'timetable', 'name' => 'Timetable', 'abilities' => ['routine.manage'], 'is_system' => false]);
        $user = $this->makeUser('timetable');

        $this->actingAs($user)->get(route('routine.editor', $this->a))->assertOk();
        $this->actingAs($user)->get(route('routine.index'))->assertOk();
    }
}
