<?php

namespace Tests\Feature;

use App\Models\Medium;
use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class ShiftMediumTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private Shift $morning;
    private Shift $day;
    private Medium $bangla;
    private Medium $english;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->morning = Shift::where('name', 'Morning')->firstOrFail();
        $this->day     = Shift::where('name', 'Day')->firstOrFail();
        $this->bangla  = Medium::where('name', 'Bangla')->firstOrFail();
        $this->english = Medium::where('name', 'English')->firstOrFail();
        $this->admin   = $this->makeUser(User::ROLE_ADMIN);
    }

    private function classIn(Shift $shift, Medium $medium, string $name = 'Class 6', string $section = 'A', ?int $year = null): SchoolClass
    {
        return $this->makeClass($name, $section, $year, ['shift_id' => $shift->id, 'medium_id' => $medium->id]);
    }

    private function admission(array $over = []): array
    {
        return array_merge([
            'studentName' => 'New Kid', 'studentId' => '91000001', 'academicYear' => (int) now()->year,
            'class' => 'Class 6', 'section' => 'A', 'admissionDate' => now()->toDateString(), 'status' => 'active',
        ], $over);
    }

    // ------------------------------------------------------------------
    // defaults
    // ------------------------------------------------------------------

    public function test_the_lists_start_with_the_usual_shifts_and_mediums(): void
    {
        $this->assertEqualsCanonicalizing(['Morning', 'Day'], Shift::pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Bangla', 'English'], Medium::pluck('name')->all());
    }

    public function test_a_class_made_without_a_shift_or_medium_gets_the_first_of_each(): void
    {
        $class = $this->makeClass('Class 3', 'A');

        $this->assertSame($this->morning->id, $class->shift_id);
        $this->assertSame($this->bangla->id, $class->medium_id);
        $this->assertSame('Morning', $class->shift_name);
        $this->assertSame('Bangla', $class->medium_name);
        $this->assertSame('Morning · Bangla', $class->shift_medium_label);
    }

    // ------------------------------------------------------------------
    // the same class in different shifts / mediums
    // ------------------------------------------------------------------

    public function test_the_same_class_and_section_can_run_in_different_shifts_and_mediums(): void
    {
        $a = $this->classIn($this->morning, $this->bangla);
        $b = $this->classIn($this->day, $this->bangla);
        $c = $this->classIn($this->morning, $this->english);

        $this->assertCount(3, array_unique([$a->id, $b->id, $c->id]));
        $this->assertSame(3, SchoolClass::where('class_name', 'Class 6')->where('section', 'A')->count());
    }

    public function test_the_database_refuses_the_same_combination_twice_for_a_grouped_class(): void
    {
        // (classes without a study group are kept unique by the forms; MySQL treats NULLs in a key as distinct)
        $group = \App\Models\StudyGroup::create(['name' => 'Science']);
        $attrs = ['class_name' => 'Class 9', 'section' => 'A', 'academic_year' => (string) now()->year, 'status' => 'active',
            'study_group_id' => $group->id, 'shift_id' => $this->morning->id, 'medium_id' => $this->bangla->id];

        SchoolClass::create($attrs);

        SchoolClass::create(array_merge($attrs, ['shift_id' => $this->day->id]));          // another shift: fine

        $this->expectException(\Illuminate\Database\QueryException::class);
        SchoolClass::create($attrs);
    }

    public function test_admin_creates_classes_for_a_chosen_shift_and_medium(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $year    = (string) now()->year;
        $form    = fn (array $over = []) => array_merge([
            'className' => 'Class 7', 'section' => 'A, B', 'academicYear' => $year, 'classTeacher' => $teacher->id, 'status' => 'active',
            'shiftId' => $this->day->id, 'mediumId' => $this->english->id,
        ], $over);

        $this->actingAs($this->admin)->post('/classesmanagement', $form())->assertSessionHas('success');

        $made = SchoolClass::where('class_name', 'Class 7')->get();
        $this->assertCount(2, $made);
        $this->assertSame([$this->day->id], $made->pluck('shift_id')->unique()->all());
        $this->assertSame([$this->english->id], $made->pluck('medium_id')->unique()->all());

        // the same sections in another shift are different classes, not duplicates
        $this->actingAs($this->admin)->post('/classesmanagement', $form(['shiftId' => $this->morning->id]))->assertSessionHas('success');
        $this->assertSame(4, SchoolClass::where('class_name', 'Class 7')->count());

        // ... but the same shift and medium again is a duplicate
        $this->actingAs($this->admin)->post('/classesmanagement', $form())->assertSessionHasErrors('className');
        $this->assertSame(4, SchoolClass::where('class_name', 'Class 7')->count());
    }

    public function test_editing_a_class_cannot_collide_with_another_shift_of_the_same_class(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $morning = $this->classIn($this->morning, $this->bangla);
        $day     = $this->classIn($this->day, $this->bangla);

        $edit = fn (SchoolClass $c, array $over) => $this->actingAs($this->admin)->put("/classesmanagement/{$c->id}", array_merge([
            'className' => 'Class 6', 'section' => 'A', 'academicYear' => (string) now()->year, 'classTeacher' => $teacher->id,
            'status' => 'active', 'shiftId' => $c->shift_id, 'mediumId' => $c->medium_id,
        ], $over));

        $edit($day, ['shiftId' => $this->morning->id])->assertSessionHasErrors('className');     // would equal the morning class
        $this->assertSame($this->day->id, $day->fresh()->shift_id);

        $edit($day, ['mediumId' => $this->english->id])->assertSessionHasNoErrors();              // free combination
        $this->assertSame($this->english->id, $day->fresh()->medium_id);
        $this->assertSame($this->bangla->id, $morning->fresh()->medium_id);
    }

    public function test_a_new_year_copies_each_classs_shift_and_medium(): void
    {
        $this->classIn($this->day, $this->english, 'Class 6', 'A', 2026);
        $this->classIn($this->morning, $this->bangla, 'Class 6', 'A', 2026);

        SchoolClass::ensureForYear(2027);

        $next = SchoolClass::where('academic_year', '2027')->get();

        $this->assertCount(2, $next);
        $this->assertEqualsCanonicalizing(
            ["{$this->day->id}-{$this->english->id}", "{$this->morning->id}-{$this->bangla->id}"],
            $next->map(fn ($c) => "{$c->shift_id}-{$c->medium_id}")->all()
        );
    }

    // ------------------------------------------------------------------
    // admission and editing
    // ------------------------------------------------------------------

    public function test_a_class_that_runs_once_needs_no_shift_or_medium_on_the_form(): void
    {
        $this->classIn($this->day, $this->english);

        $this->actingAs($this->admin)->post('/students', $this->admission())->assertSessionHasNoErrors();

        $this->assertNotNull(Student::firstOrFail()->class_id);
    }

    public function test_when_a_class_runs_in_two_shifts_the_form_must_say_which(): void
    {
        $morning = $this->classIn($this->morning, $this->bangla);
        $day     = $this->classIn($this->day, $this->bangla);

        $this->actingAs($this->admin)->post('/students', $this->admission())
            ->assertSessionHasErrors(['class' => 'This section exists more than once. Please choose the shift.']);
        $this->assertSame(0, Student::count());

        $this->actingAs($this->admin)->post('/students', $this->admission(['shiftId' => $day->shift_id]))->assertSessionHasNoErrors();

        $this->assertSame($day->id, Student::firstOrFail()->class_id);
        $this->assertNotSame($morning->id, Student::firstOrFail()->class_id);
    }

    public function test_the_error_names_exactly_what_is_ambiguous(): void
    {
        $this->classIn($this->morning, $this->bangla);
        $this->classIn($this->morning, $this->english);

        $this->actingAs($this->admin)->post('/students', $this->admission())
            ->assertSessionHasErrors(['class' => 'This section exists more than once. Please choose the medium.']);

        $this->actingAs($this->admin)->post('/students', $this->admission(['mediumId' => $this->english->id]))->assertSessionHasNoErrors();
        $this->assertSame($this->english->id, Student::firstOrFail()->schoolClass->medium_id);
    }

    public function test_an_edit_that_keeps_the_class_does_not_have_to_repeat_the_shift(): void
    {
        $this->classIn($this->morning, $this->bangla);
        $day = $this->classIn($this->day, $this->bangla);
        $kid = $this->makeStudent($day, 'Day Kid', ['student_id' => '91000009']);

        // same class, new name; the student's own shift keeps the class unambiguous
        $this->actingAs($this->admin)->put("/students/{$kid->id}", $this->admission(['studentName' => 'Renamed', 'studentId' => '91000009']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $kid->fresh()->student_name);
        $this->assertSame($day->id, $kid->fresh()->class_id);
    }

    public function test_a_student_can_move_to_a_class_that_exists_in_another_shift_only(): void
    {
        $dayClass = $this->classIn($this->day, $this->bangla, 'Class 6', 'A');
        $kid      = $this->makeStudent($dayClass, 'Mover', ['student_id' => '91000010']);
        $morning7 = $this->classIn($this->morning, $this->bangla, 'Class 7', 'A');            // the only Class 7 A there is

        $this->actingAs($this->admin)->put("/students/{$kid->id}", $this->admission([
            'studentName' => 'Mover', 'studentId' => '91000010', 'class' => 'Class 7',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($morning7->id, $kid->fresh()->class_id);
    }

    // ------------------------------------------------------------------
    // moving up a year
    // ------------------------------------------------------------------

    public function test_students_keep_their_shift_and_medium_when_moved_to_the_next_class(): void
    {
        $from = $this->classIn($this->day, $this->english, 'Class 6', 'A', 2026);
        $this->classIn($this->morning, $this->bangla, 'Class 7', 'A', 2027);
        $target = $this->classIn($this->day, $this->english, 'Class 7', 'A', 2027);
        $kid    = Student::create(['student_name' => 'Riser', 'academic_year' => 2026, 'class_id' => $from->id, 'admission_date' => '2026-01-05']);

        $this->actingAs($this->admin)->post('/student-results/shift', [
            'student_ids' => [$kid->id], 'target_class' => 'Class 7', 'target_section' => 'A', 'from_year' => 2026, 'to_year' => 2027,
        ])->assertSessionHas('success');

        $moved = Student::where('academic_year', 2027)->firstOrFail();

        $this->assertSame($target->id, $moved->class_id);
        $this->assertSame($kid->student_id, $moved->student_id);
    }

    // ------------------------------------------------------------------
    // managing the lists
    // ------------------------------------------------------------------

    public function test_admin_adds_edits_and_deactivates_a_shift(): void
    {
        $this->actingAs($this->admin)->post('/class-settings/shifts', ['name' => '  Evening   Shift '])->assertSessionHas('success');

        $evening = Shift::where('name', 'Evening Shift')->firstOrFail();          // spaces tidied
        $this->assertSame(3, $evening->sort_order);                               // goes to the end

        $this->actingAs($this->admin)->put("/class-settings/shifts/{$evening->id}", ['name' => 'Evening', 'is_active' => false])->assertSessionHas('success');
        $this->assertFalse($evening->fresh()->is_active);

        // a switched-off shift is not offered to new classes
        $this->actingAs($this->admin)->get('/classesmanagement')
            ->assertInertia(fn ($page) => $page->where('shifts', fn ($s) => collect($s)->pluck('name')->sort()->values()->all() === ['Day', 'Morning']));
    }

    public function test_names_must_be_unique_within_each_list_but_may_repeat_across_lists(): void
    {
        $this->actingAs($this->admin)->post('/class-settings/shifts', ['name' => 'morning'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post('/class-settings/mediums', ['name' => 'Bangla'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post('/class-settings/mediums', ['name' => 'Morning'])->assertSessionHasNoErrors();   // fine: a different list
    }

    public function test_a_list_entry_that_classes_use_cannot_be_deleted(): void
    {
        $this->classIn($this->day, $this->english);

        $this->actingAs($this->admin)->delete("/class-settings/shifts/{$this->day->id}")->assertSessionHas('error');
        $this->actingAs($this->admin)->delete("/class-settings/mediums/{$this->english->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('shifts', ['name' => 'Day']);

        $this->actingAs($this->admin)->delete("/class-settings/mediums/{$this->bangla->id}")->assertSessionHas('success');   // unused
        $this->assertDatabaseMissing('mediums', ['name' => 'Bangla']);
    }

    public function test_the_last_active_entry_cannot_be_switched_off_or_deleted(): void
    {
        $this->actingAs($this->admin)->put("/class-settings/mediums/{$this->english->id}", ['name' => 'English', 'is_active' => false])->assertSessionHas('success');

        $this->actingAs($this->admin)->put("/class-settings/mediums/{$this->bangla->id}", ['name' => 'Bangla', 'is_active' => false])->assertSessionHas('error');
        $this->assertTrue($this->bangla->fresh()->is_active);

        $this->actingAs($this->admin)->delete("/class-settings/mediums/{$this->bangla->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('mediums', ['name' => 'Bangla']);
    }

    public function test_only_people_who_manage_classes_reach_the_lists(): void
    {
        $this->actingAs($this->admin)->get('/class-settings')->assertOk()
            ->assertInertia(fn ($page) => $page->component('ClassSettings')->has('shifts', 2)->has('mediums', 2));

        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->get('/class-settings')->assertForbidden();
        $this->actingAs($this->makeUser(User::ROLE_CASHIER))->post('/class-settings/shifts', ['name' => 'Night'])->assertForbidden();
        $this->assertDatabaseMissing('shifts', ['name' => 'Night']);

        $this->actingAs($this->admin)->post('/class-settings/colours', ['name' => 'Red'])->assertNotFound();
    }

    // ------------------------------------------------------------------
    // results, tabulation
    // ------------------------------------------------------------------

    public function test_result_pages_show_and_filter_by_shift_and_medium(): void
    {
        $year = (int) now()->year;
        $this->classIn($this->morning, $this->bangla);
        $this->classIn($this->day, $this->english);

        foreach (['/results', '/student-results'] as $url) {
            $names = fn ($page) => collect($page->toArray()['props']['classes']['data'] ?? $page->toArray()['props']['classes'])
                ->map(fn ($c) => ($c['shift'] ?? '') . '/' . ($c['medium'] ?? ''))->sort()->values()->all();

            $this->actingAs($this->admin)->get("{$url}?academic_year={$year}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('shifts', 2)->has('mediums', 2)
                    ->where('classes', fn ($c) => collect($c['data'] ?? $c)->count() === 2));

            $this->actingAs($this->admin)->get("{$url}?academic_year={$year}&shift={$this->day->id}")
                ->assertInertia(fn ($page) => $page->where('filters.shift', (string) $this->day->id)
                    ->where('classes', fn ($c) => collect($c['data'] ?? $c)->pluck('shift')->unique()->values()->all() === ['Day']));

            $this->actingAs($this->admin)->get("{$url}?academic_year={$year}&medium={$this->bangla->id}")
                ->assertInertia(fn ($page) => $page->where('classes', fn ($c) => collect($c['data'] ?? $c)->pluck('medium')->unique()->values()->all() === ['Bangla']));
        }
    }

    public function test_the_tabulation_header_states_the_shift_and_medium(): void
    {
        $class = $this->classIn($this->day, $this->english);
        $this->makeStudent($class, 'Printed Kid');

        $html = $this->actingAs($this->admin)->get("/tabulation/class/{$class->id}")->assertOk()->getContent();

        $this->assertStringContainsString('<b>Shift:</b> Day', $html);
        $this->assertStringContainsString('<b>Medium:</b> English', $html);
    }
}
