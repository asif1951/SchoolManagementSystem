<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\PersonId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class PersonIdTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private string $yy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yy = now()->format('y');
    }

    // ------------------------------------------------------------------
    // the scheme: YY T NNNNN
    // ------------------------------------------------------------------

    public function test_every_kind_of_person_gets_an_eight_digit_id_of_their_type(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $admin   = $this->makeUser(User::ROLE_ADMIN);
        $cashier = $this->makeUser(User::ROLE_CASHIER);
        $public  = $this->makeUser(User::ROLE_USER);
        $student = $this->makeStudent($this->makeClass(), 'Kid', ['student_id' => null]);

        $this->assertMatchesRegularExpression('/^\d{8}$/', $teacher->uid);

        $this->assertSame("{$this->yy}2", substr($teacher->uid, 0, 3));          // 2 = teacher
        $this->assertSame("{$this->yy}3", substr($admin->uid, 0, 3));            // 3 = staff (admin, cashier, finance, exam controller …)
        $this->assertSame("{$this->yy}3", substr($cashier->uid, 0, 3));
        $this->assertSame("{$this->yy}4", substr($public->uid, 0, 3));           // 4 = other accounts
        $this->assertSame("{$this->yy}1", substr($student->student_id, 0, 3));   // 1 = student
    }

    public function test_ids_count_up_per_type_and_are_unique_across_everyone(): void
    {
        $users = collect(range(1, 5))->map(fn () => $this->makeUser(User::ROLE_TEACHER));

        $this->assertSame(
            collect(range(1, 5))->map(fn ($n) => "{$this->yy}2" . str_pad((string) $n, 5, '0', STR_PAD_LEFT))->all(),
            $users->pluck('uid')->all()
        );

        $this->assertSame(5, User::distinct('uid')->count('uid'));
    }

    public function test_an_id_never_changes_when_the_role_changes(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $uid     = $teacher->uid;

        $teacher->update(['role' => User::ROLE_CASHIER]);

        $this->assertSame($uid, $teacher->fresh()->uid);
        $this->assertSame("{$this->yy}2", substr($uid, 0, 3));                    // still the number it was born with
    }

    public function test_a_staff_profile_shares_the_ID_of_the_account_with_the_same_email(): void
    {
        $user    = $this->makeUser(User::ROLE_TEACHER, ['email' => 'sir@school.test']);
        $profile = Teacher::create(['name' => 'Sir', 'email' => 'sir@school.test', 'status' => 'active']);
        $other   = Teacher::create(['name' => 'No account', 'status' => 'active']);

        $this->assertSame($user->uid, $profile->uid);
        $this->assertNotSame($user->uid, $other->uid);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $other->uid);
    }

    public function test_a_student_keeps_the_same_id_when_moved_to_the_next_year(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $from  = $this->makeClass('Class 1', 'A', 2026);
        $to    = $this->makeClass('Class 2', 'A', 2027);
        $kid   = Student::create(['student_name' => 'Mover', 'academic_year' => 2026, 'class_id' => $from->id, 'admission_date' => '2026-01-05']);

        $this->actingAs($admin)->post('/student-results/shift', [
            'student_ids' => [$kid->id], 'target_class' => 'Class 2', 'target_section' => 'A', 'from_year' => 2026, 'to_year' => 2027,
        ])->assertSessionHas('success');

        $moved = Student::where('academic_year', 2027)->firstOrFail();

        $this->assertSame($kid->student_id, $moved->student_id);
        $this->assertSame($to->id, $moved->class_id);
    }

    // ------------------------------------------------------------------
    // counter safety
    // ------------------------------------------------------------------

    public function test_peek_shows_the_next_id_without_using_it_up(): void
    {
        $this->assertSame("{$this->yy}100001", PersonId::peek(PersonId::STUDENT));
        $this->assertSame("{$this->yy}100001", PersonId::peek(PersonId::STUDENT));
        $this->assertSame("{$this->yy}100001", PersonId::next(PersonId::STUDENT));
        $this->assertSame("{$this->yy}100002", PersonId::peek(PersonId::STUDENT));
    }

    public function test_an_id_typed_in_by_hand_is_never_handed_out_again(): void
    {
        $this->makeStudent($this->makeClass(), 'Hand', ['student_id' => "{$this->yy}100003"]);

        PersonId::claim("{$this->yy}100003");

        $this->assertSame("{$this->yy}100004", PersonId::next(PersonId::STUDENT));
    }

    public function test_a_number_already_in_use_is_skipped_even_if_the_counter_was_never_told(): void
    {
        $this->makeStudent($this->makeClass(), 'Hand', ['student_id' => "{$this->yy}100001"]);

        $this->assertSame("{$this->yy}100002", PersonId::next(PersonId::STUDENT));
    }

    public function test_the_year_decides_the_first_two_digits(): void
    {
        $this->assertSame('30100001', PersonId::next(PersonId::STUDENT, 2030));
        $this->assertSame('30100002', PersonId::next(PersonId::STUDENT, 2030));
        $this->assertSame("{$this->yy}100001", PersonId::next(PersonId::STUDENT));        // another year has its own count
    }

    // ------------------------------------------------------------------
    // the admission form
    // ------------------------------------------------------------------

    public function test_admission_requires_an_eight_digit_id_and_records_it(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $class = $this->makeClass('Class 5', 'A');

        $form = fn (string $id) => [
            'studentName' => 'New Kid', 'studentId' => $id, 'academicYear' => (int) $class->academic_year,
            'class' => 'Class 5', 'section' => 'A', 'admissionDate' => now()->toDateString(), 'status' => 'active',
        ];

        $this->actingAs($admin)->post('/students', $form('STD-2026-1'))->assertSessionHasErrors('studentId');

        $shown = $this->actingAs($admin)->getJson('/students/generate-id')->assertOk()->json('generatedStudentId');
        $this->assertSame("{$this->yy}100001", $shown);

        $this->actingAs($admin)->post('/students', $form($shown))->assertSessionHasNoErrors();

        $this->assertSame($shown, Student::firstOrFail()->student_id);
        $this->assertSame("{$this->yy}100002", PersonId::next(PersonId::STUDENT));          // the typed ID was claimed
    }

    public function test_students_added_from_student_management_get_the_next_id_automatically(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $class = $this->makeClass('Class 5', 'A');

        $this->actingAs($admin)->post('/students', [
            'studentName' => 'Via admission', 'studentId' => "{$this->yy}100001", 'academicYear' => (int) $class->academic_year,
            'class' => 'Class 5', 'section' => 'A', 'admissionDate' => now()->toDateString(), 'status' => 'active',
        ]);

        $this->assertMatchesRegularExpression('/^\d{8}$/', Student::firstOrFail()->student_id);
    }

    // ------------------------------------------------------------------
    // signing in with the ID
    // ------------------------------------------------------------------

    public function test_a_user_can_sign_in_with_the_id_or_the_email(): void
    {
        $user = $this->makeUser(User::ROLE_TEACHER, ['email' => 'sir@school.test']);

        $this->post('/login', ['email' => $user->uid, 'password' => 'password'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($user);

        \Illuminate\Support\Facades\Auth::guard('web')->logout();

        $this->post('/login', ['email' => 'sir@school.test', 'password' => 'password'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_or_unknown_id_does_not_sign_in(): void
    {
        $user = $this->makeUser(User::ROLE_TEACHER);

        $this->post('/login', ['email' => $user->uid, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => '99999999', 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_suspended_account_cannot_sign_in_by_id_either(): void
    {
        $user = $this->makeUser(User::ROLE_TEACHER, ['status' => User::STATUS_SUSPENDED]);

        $this->post('/login', ['email' => $user->uid, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
