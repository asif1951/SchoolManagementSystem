<?php

namespace Tests\Feature;

use App\Models\Attendance\Record;
use App\Models\ContactMessage;
use App\Models\ResultInput;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class SchoolBehaviorTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData, \Tests\Concerns\RecordsAttendance;

    // ------------------------------------------------------------------
    // class_id linking
    // ------------------------------------------------------------------

    public function test_creating_a_student_links_it_to_the_exact_class(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $a = $this->makeClass('Class 1', 'A');
        $b = $this->makeClass('Class 1', 'B');

        $this->actingAs($admin)->post('/students', [
            'studentName'   => 'Linked Kid',
            'studentId'     => '91000001',
            'academicYear'  => (int) $b->academic_year,
            'class'         => 'Class 1',
            'section'       => 'B',
            'admissionDate' => now()->toDateString(),
            'status'        => 'active',
        ])->assertSessionHasNoErrors();

        $student = Student::with('schoolClass')->where('student_name', 'Linked Kid')->firstOrFail();

        $this->assertSame($b->id, $student->class_id);
        $this->assertSame('Class 1', $student->class);   // accessor, read from the class
        $this->assertSame('B', $student->section);
        $this->assertNotSame($a->id, $student->class_id);
        $this->assertSame(26, strlen($student->public_token));
    }

    public function test_student_cannot_be_created_in_a_class_that_does_not_exist(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->makeClass('Class 1', 'A');

        $this->actingAs($admin)->post('/students', [
            'studentName'   => 'Ghost Kid',
            'studentId'     => '91000002',
            'academicYear'  => now()->year,
            'class'         => 'Class 1',
            'section'       => 'Z',
            'admissionDate' => now()->toDateString(),
            'status'        => 'active',
        ])->assertSessionHasErrors('class');

        $this->assertDatabaseMissing('students', ['student_name' => 'Ghost Kid']);
    }

    public function test_database_refuses_a_student_without_a_class(): void
    {
        $this->expectException(QueryException::class);

        DB::table('students')->insert([
            'student_name' => 'Orphan', 'student_id' => 'O-1', 'academic_year' => 2026,
            'admission_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_class_with_students_cannot_be_deleted(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $class = $this->makeClass();
        $this->makeStudent($class);

        $this->actingAs($admin)->delete("/classesmanagement/{$class->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('classes', ['id' => $class->id]);
    }

    public function test_new_students_get_sequential_eight_digit_ids(): void
    {
        $class = $this->makeClass();
        $yy    = now()->format('y');

        $first  = Student::create(['student_name' => 'First', 'academic_year' => (int) $class->academic_year, 'class_id' => $class->id, 'admission_date' => now()->toDateString()]);
        $second = Student::create(['student_name' => 'Second', 'academic_year' => (int) $class->academic_year, 'class_id' => $class->id, 'admission_date' => now()->toDateString()]);

        $this->assertSame("{$yy}100001", $first->student_id);          // year, type 1 = student, serial
        $this->assertSame("{$yy}100002", $second->student_id);
        $this->assertSame("{$yy}100003", Student::nextStudentId());     // shown on the form, not used up
        $this->assertSame("{$yy}100003", Student::nextStudentId());
    }

    // ------------------------------------------------------------------
    // attendance
    // ------------------------------------------------------------------

    public function test_teacher_can_take_attendance_and_resaving_updates_in_place(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $class   = $this->makeClass(teacher: $teacher);
        $student = $this->makeStudent($class);

        $payload = fn (string $status, ?int $revision) => [
            'class_id' => $class->id,
            'date'     => \App\Services\AcademicCalendar::today(),
            'revision' => $revision,
            'entries'  => [['student_id' => $student->id, 'status' => $status]],
        ];
        app(\App\Services\RoutineService::class)->setWorkingDays([0, 1, 2, 3, 4, 5, 6]);

        $this->actingAs($teacher)->post('/attendance', $payload('present', null))->assertSessionHasNoErrors();
        $this->actingAs($teacher)->post('/attendance', $payload('absent', 1))->assertSessionHasNoErrors();

        $this->assertSame(1, Record::where('student_id', $student->id)->count());
        $this->assertSame('absent', Record::where('student_id', $student->id)->value('status'));
        $this->assertSame($class->id, Record::where('student_id', $student->id)->value('class_id'));
    }

    public function test_attendance_cannot_be_written_for_another_classes_student(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $mine    = $this->makeClass('Class 1', 'A', teacher: $teacher);
        $other   = $this->makeClass('Class 2', 'A');
        $foreign = $this->makeStudent($other);

        app(\App\Services\RoutineService::class)->setWorkingDays([0, 1, 2, 3, 4, 5, 6]);

        $this->actingAs($teacher)->post('/attendance', [
            'class_id' => $mine->id,
            'date'     => \App\Services\AcademicCalendar::today(),
            'entries'  => [['student_id' => $foreign->id, 'status' => 'present']],
        ])->assertSessionHasErrors('attendance');

        $this->assertSame(0, Record::count());
    }

    // ------------------------------------------------------------------
    // fees
    // ------------------------------------------------------------------

    public function test_payments_accumulate_exactly_in_one_row_with_a_log_entry_each(): void
    {
        $staff   = $this->makeUser(User::ROLE_CASHIER);
        $student = $this->makeStudent($this->makeClass());
        $year    = (string) $student->academic_year;

        foreach (['100.10', '50.20'] as $amount) {
            $this->actingAs($staff)->post('/fees/collect-payment', [
                'student_id' => $student->id, 'academic_year' => $year, 'amount' => $amount,
            ])->assertSessionHas('success');
        }

        $this->assertSame(1, DB::table('fee_payments')->where('student_id', $student->id)->count());
        $this->assertSame('150.30', DB::table('fee_payments')->where('student_id', $student->id)->value('amount'));
        $this->assertSame(2, DB::table('fee_payment_logs')->where('student_id', $student->id)->count());
    }

    public function test_invalid_payments_are_rejected(): void
    {
        $staff   = $this->makeUser(User::ROLE_CASHIER);
        $student = $this->makeStudent($this->makeClass());
        $year    = (string) $student->academic_year;

        $this->actingAs($staff)->post('/fees/collect-payment', ['student_id' => 999999, 'academic_year' => $year, 'amount' => 5])
            ->assertSessionHasErrors('student_id');
        $this->actingAs($staff)->post('/fees/collect-payment', ['student_id' => $student->id, 'academic_year' => $year, 'amount' => -5])
            ->assertSessionHasErrors('amount');
        $this->actingAs($staff)->post('/fees/collect-payment', ['student_id' => $student->id, 'academic_year' => '1999', 'amount' => 5])
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('fee_payments')->count());
    }

    public function test_plain_users_cannot_collect_payments(): void
    {
        $student = $this->makeStudent($this->makeClass());

        $this->actingAs($this->makeUser(User::ROLE_USER))
            ->post('/fees/collect-payment', ['student_id' => $student->id, 'academic_year' => (string) $student->academic_year, 'amount' => 5])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // public pages
    // ------------------------------------------------------------------

    public function test_public_result_works_by_roll_and_ranks_across_the_whole_class(): void
    {
        $class = $this->makeClass('Class 9', null);
        $year  = (string) $class->academic_year;

        $students = [];
        foreach ([90, 60, 75] as $i => $marks) {
            $s = $this->makeStudent($class, "Kid{$i}", ['roll_number' => (string) (10 + $i)]);
            ResultInput::create([
                'student_id' => $s->id, 'class_id' => $class->id, 'subject' => 'Math', 'exam_type' => 'final',
                'academic_year' => $year, 'marks' => $marks, 'total_marks' => $marks, 'grade' => 'A', 'gpa' => 5,
            ]);
            $students[$marks] = $s;
        }

        $res = $this->postJson('/result/fetch', [
            'student_id' => '11', 'class_id' => $class->id, 'year' => $year, 'exam' => 'final',
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertSame('Kid1', $res['student']['name']);
        $this->assertSame(3, $res['student']['position']);        // 60 is last of 90/75/60
        $this->assertSame(3, $res['student']['total_students']);
        $this->assertEquals(90, $res['subjects'][0]['highest']);  // best in the class, not just this student
    }

    public function test_public_result_cannot_be_found_by_internal_database_id(): void
    {
        $class = $this->makeClass('Class 9', null);
        $s     = $this->makeStudent($class, 'Hidden', ['roll_number' => 'R-77', 'student_id' => 'STD-HIDDEN']);
        ResultInput::create([
            'student_id' => $s->id, 'class_id' => $class->id, 'subject' => 'Math', 'exam_type' => 'final',
            'academic_year' => (string) $class->academic_year, 'marks' => 80, 'total_marks' => 80, 'grade' => 'A', 'gpa' => 5,
        ]);

        $this->postJson('/result/fetch', [
            'student_id' => (string) $s->id, 'class_id' => $class->id, 'year' => (string) $class->academic_year, 'exam' => 'final',
        ])->assertNotFound();
    }

    public function test_verify_page_uses_token_and_hides_private_details(): void
    {
        $class   = $this->makeClass();
        $student = $this->makeStudent($class, 'Public Kid', ['father_phone' => '01700000000', 'blood_group' => 'O+', 'date_of_birth' => '2015-05-05']);

        $this->get("/student/{$student->public_token}/verify")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Verify')
                ->where('student.student_name', 'Public Kid')
                ->missing('student.father_phone')
                ->missing('student.blood_group')
                ->missing('student.date_of_birth'));

        // Sequential ids no longer work.
        $this->get("/student/{$student->id}/verify")->assertNotFound();
    }

    public function test_contact_form_really_stores_the_message(): void
    {
        $this->post('/contact', [
            'name' => 'Parent', 'email' => 'parent@example.com', 'subject' => 'Admission', 'message' => 'Hello school',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ContactMessage::where('email', 'parent@example.com')->count());

        $this->post('/contact', ['name' => '', 'email' => 'not-an-email', 'message' => ''])
            ->assertSessionHasErrors(['name', 'email', 'message']);
    }

    // ------------------------------------------------------------------
    // class-wide ranking in the admin result pages
    // ------------------------------------------------------------------

    public function test_admin_rank_is_class_wide_not_per_page(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $class = $this->makeClass('Class 8', 'R');
        $year  = (string) $class->academic_year;

        for ($i = 1; $i <= 15; $i++) {
            $s = $this->makeStudent($class, sprintf('R%02d', $i), ['roll_number' => sprintf('%02d', $i)]);
            ResultInput::create([
                'student_id' => $s->id, 'class_id' => $class->id, 'subject' => 'Math', 'exam_type' => 'final',
                'academic_year' => $year, 'marks' => $i * 6, 'total_marks' => $i * 6, 'grade' => 'A', 'gpa' => 5,
            ]);
        }

        $this->actingAs($admin)->get("/student-results/class/{$class->id}?exam_type=final")
            ->assertOk()
            ->assertInertia(function ($page) {
                $rows = collect($page->toArray()['props']['students']['data']);
                // Page 1 holds R01..R12; R01 scored lowest of all 15 → rank 15 (not 12).
                $this->assertSame(15, $rows->firstWhere('student_name', 'R01')['rank']);
            });
    }

    public function test_shifting_keeps_the_student_id_links_back_and_never_duplicates(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $from  = $this->makeClass('Class 1', 'A', 2026);
        $to    = $this->makeClass('Class 2', 'A', 2027);
        $kid   = $this->makeStudent($from, 'Mover', ['academic_year' => 2026, 'student_id' => 'STD-2026-00001', 'roll_number' => '5']);

        $shift = fn () => $this->actingAs($admin)->post('/student-results/shift', [
            'student_ids' => [$kid->id], 'target_class' => 'Class 2', 'target_section' => 'A',
            'from_year' => 2026, 'to_year' => 2027,
        ])->assertSessionHas('success');

        $shift();
        $shift();   // doing it twice must not create a second 2027 record

        $moved = Student::where('academic_year', 2027)->where('student_name', 'Mover')->get();

        $this->assertCount(1, $moved);
        $this->assertSame($to->id, $moved[0]->class_id);
        $this->assertSame('STD-2026-00001', $moved[0]->student_id);          // same ID for life
        $this->assertSame($kid->id, $moved[0]->promoted_from_id);            // linked to last year's record
        $this->assertNotSame($kid->public_token, $moved[0]->public_token);   // but its own QR token

        // The 2026 record is untouched: still Class 1, still listed.
        $this->assertSame($from->id, $kid->fresh()->class_id);
        $this->assertSame(1, Student::where('class_id', $from->id)->count());
    }
}
