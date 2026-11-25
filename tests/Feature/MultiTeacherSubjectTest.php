<?php

namespace Tests\Feature;

use App\Models\ClassRoutineSlot;
use App\Models\ClassSubjectTeacher;
use App\Models\RoutinePeriod;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\RoutineGenerator;
use App\Services\RoutineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

/** A subject can have more than one teacher in a class. */
class MultiTeacherSubjectTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private User $physics;
    private User $chemistry;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->physics = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Physics Sir']);
        $this->chemistry = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Chemistry Madam']);
        $this->class = $this->makeClass('Class 9', 'A', null, ['subjects' => ['Science', 'Math']]);
    }

    private function open(string $subject = 'Science')
    {
        return ClassSubjectTeacher::where('class_id', $this->class->id)->where('subject', $subject)->whereNull('ends_on');
    }

    public function test_a_subject_can_have_two_teachers_at_once(): void
    {
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry], $this->admin);

        $this->assertSame([$this->physics->id, $this->chemistry->id], $this->open()->orderBy('id')->pluck('user_id')->all());

        $lists = $this->class->currentSubjectTeacherLists();
        $this->assertCount(2, $lists['Science']);
        $this->assertSame($this->physics->id, $this->class->currentSubjectTeachers()['Science']->user_id);     // the main one is the first assigned
    }

    public function test_changing_the_list_only_records_what_changed(): void
    {
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);
        $before = ClassSubjectTeacher::count();

        $this->class->setSubjectTeachers('Science', [$this->chemistry, $this->physics]);          // same people: nothing to record
        $this->assertSame($before, ClassSubjectTeacher::count());

        $this->class->setSubjectTeachers('Science', [$this->physics]);                            // Chemistry leaves
        $this->assertSame([$this->physics->id], $this->open()->pluck('user_id')->all());
        $this->assertSame(1, ClassSubjectTeacher::where('subject', 'Science')->whereNotNull('ends_on')->count());   // her stint is closed, not deleted

        $this->class->setSubjectTeachers('Science', []);                                          // nobody
        $this->assertSame(0, $this->open()->count());
    }

    public function test_the_older_assign_still_means_the_one_teacher(): void
    {
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);

        $this->class->assignSubjectTeacher('Science', $this->chemistry);

        $this->assertSame([$this->chemistry->id], $this->open()->pluck('user_id')->all());
    }

    public function test_a_subject_not_in_the_class_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->class->setSubjectTeachers('Physics', [$this->physics]);
    }

    public function test_every_teacher_of_the_subject_may_enter_its_marks(): void
    {
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);

        $this->assertSame(['Science'], $this->physics->fresh()->taughtSubjects()[$this->class->id]);
        $this->assertSame(['Science'], $this->chemistry->fresh()->taughtSubjects()[$this->class->id]);
    }

    public function test_the_screen_saves_a_list_of_teachers_and_still_accepts_one(): void
    {
        $post = fn (array $d) => $this->actingAs($this->admin)->post(route('subject-teachers.assign'), ['class_id' => $this->class->id, 'subject' => 'Science'] + $d);

        $post(['user_ids' => [$this->physics->id, $this->chemistry->id]])->assertSessionHas('success');
        $this->assertSame(2, $this->open()->count());

        $this->actingAs($this->admin)->get(route('subject-teachers.index', ['year' => (string) $this->class->academic_year]))
            ->assertInertia(fn (Assert $p) => $p->where('classes.0.subjects.0.teacher_ids', [$this->physics->id, $this->chemistry->id]));

        $post(['user_id' => $this->physics->id])->assertSessionHas('success');             // the older single form
        $this->assertSame([$this->physics->id], $this->open()->pluck('user_id')->all());

        $post(['user_ids' => []])->assertSessionHas('success');
        $this->assertSame(0, $this->open()->count());

        $inactive = $this->makeUser(User::ROLE_TEACHER, ['status' => User::STATUS_SUSPENDED ?? 'inactive']);
        $post(['user_ids' => [$inactive->id]])->assertSessionHasErrors('user_ids.0');
    }

    public function test_the_teachers_are_copied_to_the_next_year(): void
    {
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);

        SchoolClass::ensureForYear(((int) $this->class->academic_year) + 1);

        $next = SchoolClass::where('class_name', 'Class 9')->where('academic_year', (string) (((int) $this->class->academic_year) + 1))->firstOrFail();
        $this->assertSame([$this->physics->id, $this->chemistry->id], ClassSubjectTeacher::where('class_id', $next->id)->whereNull('ends_on')->orderBy('id')->pluck('user_id')->all());
    }

    // ---- the routine -----------------------------------------------------------------------------------------------

    private function bell(): void
    {
        foreach ([['1st', '10:00', '10:40'], ['2nd', '10:40', '11:20'], ['3rd', '11:20', '12:00'], ['4th', '12:00', '12:40'], ['5th', '12:40', '13:20'], ['6th', '13:20', '14:00']] as $i => $p) {
            RoutinePeriod::create(['name' => $p[0], 'starts_at' => $p[1], 'ends_at' => $p[2], 'sort_order' => $i + 1]);
        }
    }

    public function test_the_routine_offers_every_teacher_of_a_subject(): void
    {
        $this->bell();
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);

        $science = collect(app(RoutineService::class)->subjectsOf($this->class))->firstWhere('name', 'Science');

        $this->assertSame($this->physics->id, $science['teacher_id']);
        $this->assertSame('Physics Sir, Chemistry Madam', $science['teacher']);
        $this->assertSame([$this->physics->id, $this->chemistry->id], array_column($science['teachers'], 'id'));
    }

    public function test_a_subjects_periods_are_shared_between_its_teachers(): void
    {
        $this->bell();
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);
        $svc = app(RoutineService::class);
        $svc->savePlan($this->class, [['subject' => 'Science', 'periods_per_week' => 8, 'max_per_day' => 2]]);

        $r = app(RoutineGenerator::class)->run(collect([$this->class]), ['seed' => 3]);
        app(RoutineGenerator::class)->apply($r, collect([$this->class]), false);

        $by = ClassRoutineSlot::query()->selectRaw('teacher_id, COUNT(*) n')->groupBy('teacher_id')->pluck('n', 'teacher_id');
        $this->assertSame(8, $by->sum());
        $this->assertEqualsWithDelta(4, $by[$this->physics->id], 1);
        $this->assertEqualsWithDelta(4, $by[$this->chemistry->id], 1);
    }

    public function test_a_second_teacher_takes_the_periods_the_first_cannot(): void
    {
        $this->bell();
        $svc = app(RoutineService::class);
        $gen = app(RoutineGenerator::class);
        $b = $this->makeClass('Class 9', 'B', null, ['subjects' => ['Science']]);

        foreach ([$this->class, $b] as $c) {
            $svc->savePlan($c, [['subject' => 'Science', 'periods_per_week' => 20, 'max_per_day' => 4]]);
        }

        // one Science teacher for both sections: 40 periods asked of a teacher who has 30 in the week
        $this->class->setSubjectTeachers('Science', [$this->physics]);
        $b->setSubjectTeachers('Science', [$this->physics]);
        $alone = $gen->run(collect([$this->class, $b]), ['seed' => 1]);
        $this->assertFalse($alone['ok']);

        // with a second teacher on the subject everything fits, and nobody is in two places
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);
        $b->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);
        $both = $gen->run(collect([$this->class, $b]), ['seed' => 1]);
        $this->assertTrue($both['ok'], json_encode(collect($both['classes'])->pluck('unplaced')));

        $gen->apply($both, collect([$this->class, $b]), false);

        $slots = ClassRoutineSlot::query()->join('routine_periods as p', 'p.id', '=', 'class_routine_slots.period_id')->get(['teacher_id', 'day', 'p.starts_at as a']);
        $this->assertCount(40, $slots);
        $this->assertSame(40, $slots->unique(fn ($s) => $s->teacher_id . '|' . $s->day . '|' . $s->a)->count());   // no teacher twice at one time
    }

    public function test_the_editor_gets_the_teachers_of_each_subject(): void
    {
        $this->bell();
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);

        $this->actingAs($this->admin)->get(route('routine.editor', $this->class))
            ->assertInertia(fn (Assert $p) => $p->has('grid.subjects.0.teachers', 2)->where('grid.subjects.0.teacher', 'Physics Sir, Chemistry Madam'));
    }

    public function test_a_cell_and_the_print_name_all_the_teachers_of_the_subject(): void
    {
        $this->bell();
        $svc = app(RoutineService::class);
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);
        $period = $svc->periodsForClass($this->class)->first();

        $svc->setCell($this->class, 0, $period->id, 'Science', null, $this->chemistry->id);          // Chemistry Madam takes this period

        $cell = $svc->grid($this->class)['cells']["0-{$period->id}"];
        $this->assertSame('Chemistry Madam', $cell['teacher']);                                         // who really takes it
        $this->assertSame(['Chemistry Madam', 'Physics Sir'], $cell['all_teachers']);                   // and everyone who teaches the subject, her first

        $svc->publish($this->class, $this->admin);

        $this->actingAs($this->admin)->get(route('routine.print', $this->class))->assertOk()->assertSee('Chemistry Madam / Physics Sir');
        $this->actingAs($this->admin)->get(route('routine.print', ['class' => $this->class->id, 'who' => 'one']))->assertOk()->assertDontSee('Chemistry Madam / Physics Sir');
    }

    public function test_removing_a_teacher_from_a_subject_takes_them_off_its_periods_in_the_routine(): void
    {
        $this->bell();
        $svc = app(RoutineService::class);
        $third = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Third Sir']);
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry, $third]);
        $periods = $svc->periodsForClass($this->class)->values();

        $svc->setCell($this->class, 0, $periods[0]->id, 'Science', null, $this->chemistry->id);
        $svc->setCell($this->class, 1, $periods[1]->id, 'Science', null, $this->physics->id);
        $svc->publish($this->class, $this->admin);

        // physics is busy in another class at Sunday's 1st period, so he cannot take over there
        $other = $this->makeClass('Class 8', 'A', null, ['subjects' => ['Bangla']]);
        $other->setSubjectTeachers('Bangla', [$this->physics]);
        $svc->setCell($other, 0, $periods[0]->id, 'Bangla', null, $this->physics->id);

        $moved = $this->class->setSubjectTeachers('Science', [$this->physics, $third]);          // Chemistry Madam is removed

        $this->assertSame(2, $moved['changed']);                                                   // her period, in the draft and in the published copy
        $this->assertSame(['Third Sir' => 1], $moved['moved']);
        $draft = $svc->grid($this->class)['cells'];
        $this->assertSame($third->id, $draft["0-{$periods[0]->id}"]['teacher_id']);                // physics was busy then: the third teacher takes it
        $this->assertSame($this->physics->id, $draft["1-{$periods[1]->id}"]['teacher_id']);        // untouched
        $this->assertNotContains('Chemistry Madam', $draft["0-{$periods[0]->id}"]['all_teachers']);

        $published = $svc->grid($this->class, true)['cells'];
        $this->assertSame($third->id, $published["0-{$periods[0]->id}"]['teacher_id']);            // the published routine no longer names her either
        $this->assertFalse($svc->grid($this->class)['changed']);                                   // and this is not an edit waiting to be published
    }

    public function test_when_nobody_is_left_the_period_stays_but_has_no_teacher(): void
    {
        $this->bell();
        $svc = app(RoutineService::class);
        $this->class->setSubjectTeachers('Science', [$this->physics]);
        $p = $svc->periodsForClass($this->class)->first();
        $svc->setCell($this->class, 0, $p->id, 'Science', null, $this->physics->id);

        $r = $this->class->setSubjectTeachers('Science', []);
        $this->assertSame(['Sunday 1st'], $r['without']);                                          // the screen can say which period is left without a teacher

        $cell = $svc->grid($this->class)['cells']["0-{$p->id}"];
        $this->assertSame('Science', $cell['subject']);
        $this->assertNull($cell['teacher_id']);
        $this->assertSame([], $cell['all_teachers']);
    }

    public function test_the_screen_says_when_it_moved_routine_periods(): void
    {
        $this->bell();
        $svc = app(RoutineService::class);
        $this->class->setSubjectTeachers('Science', [$this->physics, $this->chemistry]);
        $svc->setCell($this->class, 0, $svc->periodsForClass($this->class)->first()->id, 'Science', null, $this->chemistry->id);

        $this->actingAs($this->admin)->post(route('subject-teachers.assign'), ['class_id' => $this->class->id, 'subject' => 'Science', 'user_ids' => [$this->physics->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'In the routine, 1 period is now taken by Physics Sir'));

        // a change that takes nobody off the routine says nothing about it
        $this->actingAs($this->admin)->post(route('subject-teachers.assign'), ['class_id' => $this->class->id, 'subject' => 'Science', 'user_ids' => [$this->physics->id, $this->chemistry->id]])
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'routine'));
    }
}
