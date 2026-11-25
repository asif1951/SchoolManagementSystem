<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ResultInput;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\TabulationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudentLifecycleTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class = $this->makeClass('Class 6', 'A', attrs: ['subjects' => ['Math']]);
    }

    private function marks(Student $s): void
    {
        ResultInput::create([
            'student_id' => $s->id, 'class_id' => $s->class_id, 'subject' => 'Math', 'exam_type' => 'final',
            'academic_year' => (string) $this->class->academic_year, 'marks' => 70, 'total_marks' => 0, 'grade' => 'A', 'gpa' => 4,
        ]);
    }

    private function admission(array $over = []): array
    {
        return array_merge([
            'studentName' => 'Rahim Uddin', 'studentId' => '91000001', 'academicYear' => (int) now()->year,
            'class' => 'Class 6', 'section' => 'A', 'admissionDate' => now()->toDateString(), 'status' => 'active',
        ], $over);
    }

    // ------------------------------------------------------------------
    // status lifecycle
    // ------------------------------------------------------------------

    public function test_a_student_can_be_marked_dropped_with_a_date_and_reason_and_keeps_every_record(): void
    {
        $kid = $this->makeStudent($this->class, 'Leaver');
        $this->marks($kid);

        $this->actingAs($this->admin)->post("/students/{$kid->id}/status", [
            'status' => 'dropped', 'effective_on' => '2026-03-10', 'reason' => 'Family moved to Dhaka',
        ])->assertSessionHas('success');

        $kid->refresh();
        $this->assertSame('dropped', $kid->status);
        $this->assertSame('2026-03-10', $kid->status_changed_on->toDateString());
        $this->assertSame('Family moved to Dhaka', $kid->status_reason);
        $this->assertSame('Dropped out', $kid->status_label);

        // nothing was lost
        $this->assertSame(1, ResultInput::where('student_id', $kid->id)->count());
    }

    public function test_a_student_who_left_stops_appearing_in_current_work(): void
    {
        $stays = $this->makeStudent($this->class, 'Stays', ['roll_number' => '1']);
        $leaves = $this->makeStudent($this->class, 'Leaves', ['roll_number' => '2']);
        $this->marks($stays);
        $this->marks($leaves);

        $this->actingAs($this->admin)->post("/students/{$leaves->id}/status", ['status' => 'dropped']);

        $sheet = app(TabulationBuilder::class)->build($this->class, 'final');
        $this->assertSame(['Stays'], collect($sheet['rows'])->pluck('name')->all());
    }

    public function test_a_student_can_be_reactivated_and_the_old_reason_is_cleared(): void
    {
        $kid = $this->makeStudent($this->class);

        $this->actingAs($this->admin)->post("/students/{$kid->id}/status", ['status' => 'suspended', 'reason' => 'Discipline']);
        $this->assertSame('Discipline', $kid->fresh()->status_reason);

        $this->actingAs($this->admin)->post("/students/{$kid->id}/status", ['status' => 'active']);

        $this->assertSame('active', $kid->fresh()->status);
        $this->assertNull($kid->fresh()->status_reason);
    }

    public function test_only_real_statuses_and_past_dates_are_accepted(): void
    {
        $kid = $this->makeStudent($this->class);

        $this->actingAs($this->admin)->post("/students/{$kid->id}/status", ['status' => 'vanished'])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->post("/students/{$kid->id}/status", ['status' => 'dropped', 'effective_on' => now()->addDays(3)->toDateString()])->assertSessionHasErrors('effective_on');

        $this->assertSame('active', $kid->fresh()->status);

        // the admission and edit forms cannot invent a status either
        $this->actingAs($this->admin)->post('/students', $this->admission(['status' => 'vanished']))->assertSessionHasErrors('status');
    }

    public function test_only_people_who_may_edit_the_class_can_change_a_status(): void
    {
        $kid = $this->makeStudent($this->class);

        $this->actingAs($this->makeUser(User::ROLE_CASHIER))->post("/students/{$kid->id}/status", ['status' => 'dropped'])->assertForbidden();
        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->post("/students/{$kid->id}/status", ['status' => 'dropped'])->assertForbidden();

        $this->assertSame('active', $kid->fresh()->status);
    }

    // ------------------------------------------------------------------
    // deleting
    // ------------------------------------------------------------------

    public function test_a_student_with_marks_attendance_or_payments_cannot_be_deleted(): void
    {
        $withMarks = $this->makeStudent($this->class, 'Has Marks');
        $this->marks($withMarks);

        $withAttendance = $this->makeStudent($this->class, 'Has Attendance');
        Attendance::create(['student_id' => $withAttendance->id, 'class_id' => $this->class->id, 'attendance_date' => now()->toDateString(), 'status' => 'present', 'academic_year' => (string) $this->class->academic_year]);

        foreach ([$withMarks, $withAttendance] as $kid) {
            $this->actingAs($this->admin)->delete("/students/{$kid->id}")
                ->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot be deleted') && str_contains($m, 'Change the status instead'));
            $this->actingAs($this->admin)->delete("/studentmanagement/{$kid->id}")->assertSessionHas('error');

            $this->assertNotNull(Student::find($kid->id));
        }

        $this->assertSame(1, ResultInput::count());                 // the marks are still there
    }

    public function test_the_error_says_what_is_on_record(): void
    {
        $kid = $this->makeStudent($this->class);
        $this->marks($kid);

        $this->actingAs($this->admin)->delete("/students/{$kid->id}")
            ->assertSessionHas('error', fn ($m) => str_contains($m, '1 marks'));
    }

    public function test_a_student_who_continues_in_a_later_year_cannot_be_deleted(): void
    {
        $old = $this->makeStudent($this->class, 'Old Record');
        $next = $this->makeClass('Class 7', 'A', now()->year + 1);
        $this->makeStudent($next, 'Old Record', ['academic_year' => now()->year + 1, 'student_id' => $old->student_id, 'promoted_from_id' => $old->id]);

        $this->actingAs($this->admin)->delete("/students/{$old->id}")->assertSessionHas('error', fn ($m) => str_contains($m, 'later-year records'));
        $this->assertNotNull(Student::find($old->id));
    }

    public function test_a_mistaken_entry_with_nothing_on_record_can_be_deleted_and_its_photo_goes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('students/typo.jpg', 'x');

        $typo = $this->makeStudent($this->class, 'Typo Entry', ['student_photo' => 'students/typo.jpg']);

        $this->actingAs($this->admin)->delete("/students/{$typo->id}")->assertSessionHas('success');

        $this->assertNull(Student::find($typo->id));
        Storage::disk('public')->assertMissing('students/typo.jpg');

        $other = $this->makeStudent($this->class, 'Second Typo');
        $this->actingAs($this->admin)->delete("/studentmanagement/{$other->id}")->assertSessionHas('success');
        $this->assertNull(Student::find($other->id));
    }

    // ------------------------------------------------------------------
    // audit log
    // ------------------------------------------------------------------

    public function test_creating_changing_and_deleting_a_student_is_logged_with_who_did_it(): void
    {
        $this->actingAs($this->admin)->post('/students', $this->admission())->assertSessionHasNoErrors();
        $kid = Student::firstOrFail();

        $created = AuditLog::forRecord($kid)->where('event', 'created')->firstOrFail();
        $this->assertSame($this->admin->id, $created->user_id);
        $this->assertSame('Rahim Uddin', $created->changes['student_name']);
        $this->assertStringContainsString('91000001', $created->label);

        $this->actingAs($this->admin)->post("/students/{$kid->id}/status", ['status' => 'dropped', 'reason' => 'Moved']);

        $updated = AuditLog::forRecord($kid)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertEquals(['from' => 'active', 'to' => 'dropped'], $updated->changes['status']);     // (MySQL JSON may reorder keys)
        $this->assertEquals(['from' => null, 'to' => 'Moved'], $updated->changes['status_reason']);
        $this->assertArrayNotHasKey('updated_at', $updated->changes);

        $this->actingAs($this->admin)->delete("/students/{$kid->id}")->assertSessionHas('success');

        $deleted = AuditLog::where('auditable_id', $kid->id)->where('event', 'deleted')->firstOrFail();
        $this->assertSame('Rahim Uddin', $deleted->changes['student_name']);         // still readable after deletion
        $this->assertSame($this->admin->id, $deleted->user_id);
    }

    public function test_only_fields_that_really_changed_are_logged(): void
    {
        $kid = $this->makeStudent($this->class, 'Same Name', ['father_name' => 'Karim']);
        AuditLog::query()->delete();

        $kid->update(['student_name' => 'Same Name', 'father_name' => 'Karim']);          // nothing changed
        $this->assertSame(0, AuditLog::count());

        $kid->update(['father_name' => 'Abdul Karim', 'roll_number' => '9']);

        // one line for the enrollment; the father's name is also a profile field, which logs its own line
        $mine = AuditLog::forRecord($kid)->get();
        $this->assertSame(1, $mine->count());
        $this->assertEqualsCanonicalizing(['father_name', 'roll_number'], array_keys($mine->first()->changes));
        $this->assertEquals(['from' => 'Karim', 'to' => 'Abdul Karim'], $mine->first()->changes['father_name']);
        $this->assertSame(1, AuditLog::forRecord($kid->profile)->count());
    }

    public function test_a_change_made_with_no_one_signed_in_is_logged_without_a_user(): void
    {
        $kid = $this->makeStudent($this->class);

        $this->assertNull(AuditLog::forRecord($kid)->firstOrFail()->user_id);
    }

    // ------------------------------------------------------------------
    // duplicate warning
    // ------------------------------------------------------------------

    public function test_admitting_the_same_name_and_birth_date_asks_for_confirmation(): void
    {
        $this->makeStudent($this->class, 'Rahim Uddin', ['date_of_birth' => '2014-05-02', 'student_id' => '91000099']);

        $form = $this->admission(['dateOfBirth' => '2014-05-02']);

        $this->actingAs($this->admin)->post('/students', $form)->assertSessionHasErrors('duplicate');

        $message = session('errors')->first('duplicate');
        $this->assertStringContainsString('Rahim Uddin', $message);
        $this->assertStringContainsString('91000099', $message);          // names the student already on record
        $this->assertStringContainsString('Add anyway', $message);
        $this->assertSame(1, Student::count());

        // "Add anyway": a genuinely different child with the same name and birthday (twins, namesakes)
        $this->actingAs($this->admin)->post('/students', $form + ['confirmDuplicate' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, Student::count());
    }

    public function test_the_same_name_and_a_shared_parent_phone_is_a_likely_duplicate_whatever_the_formatting(): void
    {
        $this->makeStudent($this->class, 'Rahim Uddin', ['father_phone' => '+880 1712-345678', 'student_id' => '91000099']);

        $this->actingAs($this->admin)->post('/students', $this->admission(['fatherPhone' => '01712345678']))->assertSessionHasErrors('duplicate');
        $this->actingAs($this->admin)->post('/students', $this->admission(['guardianPhone' => '01712 345678']))->assertSessionHasErrors('duplicate');
        $this->assertSame(1, Student::count());
    }

    public function test_it_does_not_cry_wolf(): void
    {
        $this->makeStudent($this->class, 'Rahim Uddin', ['date_of_birth' => '2014-05-02', 'father_phone' => '01712345678', 'student_id' => '91000099']);

        // another child with the same birthday
        $this->actingAs($this->admin)->post('/students', $this->admission(['studentName' => 'Karim Ali', 'dateOfBirth' => '2014-05-02']))->assertSessionHasNoErrors();
        // the same name but a different birthday and a different phone
        $this->actingAs($this->admin)->post('/students', $this->admission(['studentId' => '91000003', 'dateOfBirth' => '2013-01-01', 'fatherPhone' => '01899999999']))->assertSessionHasNoErrors();
        // a too-short phone number proves nothing
        $this->actingAs($this->admin)->post('/students', $this->admission(['studentId' => '91000004', 'fatherPhone' => '12345']))->assertSessionHasNoErrors();
        // no birthday and no phone given: nothing to compare, so no warning
        $this->actingAs($this->admin)->post('/students', $this->admission(['studentId' => '91000005']))->assertSessionHasNoErrors();

        $this->assertSame(5, Student::count());
    }

    public function test_editing_a_student_never_trips_the_duplicate_warning(): void
    {
        $a = $this->makeStudent($this->class, 'Rahim Uddin', ['date_of_birth' => '2014-05-02', 'student_id' => '91000098']);

        $this->actingAs($this->admin)->put("/students/{$a->id}", $this->admission(['studentId' => '91000098', 'dateOfBirth' => '2014-05-02', 'studentName' => 'Rahim Uddin']))
            ->assertSessionHasNoErrors();
    }

    public function test_possible_duplicates_finds_the_record_and_skips_the_student_being_edited(): void
    {
        $a = $this->makeStudent($this->class, 'Rahim Uddin', ['date_of_birth' => '2014-05-02']);

        $this->assertCount(1, Student::possibleDuplicates('rahim uddin', '2014-05-02'));      // case does not matter
        $this->assertCount(0, Student::possibleDuplicates('Rahim Uddin', '2014-05-02', [], $a->id));
        $this->assertCount(0, Student::possibleDuplicates('Rahim Uddin', null, []));
    }
}
