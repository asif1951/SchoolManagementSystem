<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateStudentException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentChangeRequest;
use App\Models\StudentDuplicateOverride;
use App\Models\StudentFieldHistory;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\StudentRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudentRegistryTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private StudentRegistry $registry;
    private User $admin;
    private User $teacher;
    private SchoolClass $class;

    private const BRN_A = '20141234567890123';
    private const BRN_B = '20141234567890124';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(StudentRegistry::class);
        $this->admin    = $this->makeUser(User::ROLE_ADMIN);
        $this->teacher  = $this->makeUser(User::ROLE_TEACHER);
        $this->class    = $this->makeClass('Class 6', 'A');
    }

    private function data(array $over = []): array
    {
        return array_merge([
            'name_en' => 'Rahim Uddin', 'dob' => '2014-05-02', 'gender' => 'Male', 'father_name' => 'Karim Uddin',
            'guardian_mobile' => '01712345678', 'class_id' => $this->class->id,
        ], $over);
    }

    private function admit(array $over = [], ?User $by = null, ?string $reason = null): Student
    {
        return $this->registry->admit($this->data($over), $by ?? $this->admin, 'entry', $reason);
    }

    private function duplicate(callable $fn): DuplicateStudentException
    {
        try {
            $fn();
        } catch (DuplicateStudentException $e) {
            return $e;
        }

        $this->fail('Expected a DuplicateStudentException.');
    }

    private function clerk(array $extra = []): User
    {
        // one role per combination of extra permissions, so a test can ask for several kinds of user
        $slug = 'clerk_' . substr(md5(json_encode($extra)), 0, 8);
        Role::firstOrCreate(['slug' => $slug], ['name' => 'Clerk', 'abilities' => array_merge(['students.view', 'students.edit'], $extra), 'is_system' => false]);

        return $this->makeUser($slug);
    }

    // ------------------------------------------------------------------
    // admission
    // ------------------------------------------------------------------

    public function test_admitting_creates_the_profile_and_the_enrollment_together(): void
    {
        $s = $this->admit(['roll_number' => '7', 'brn' => '2014 1234 5678 9012 3', 'religion' => 'Islam']);

        $p = $s->profile;
        $this->assertMatchesRegularExpression('/^\d{8}$/', $p->code);
        $this->assertSame($p->code, $s->student_id);
        $this->assertSame(self::BRN_A, $p->brn);
        $this->assertSame('Islam', $p->religion);
        $this->assertSame($this->admin->id, $p->created_by);

        $this->assertSame($this->class->id, $s->class_id);
        $this->assertSame((int) $this->class->academic_year, (int) $s->academic_year);
        $this->assertSame('7', $s->roll_number);
        $this->assertSame('active', $s->status);
        $this->assertSame('Rahim Uddin', $s->student_name);          // the synchronized copy
        $this->assertSame('2014-05-02', $s->date_of_birth);
        $this->assertSame('01712345678', $s->guardian_phone);
    }

    public function test_a_name_is_required_and_dates_and_phones_are_checked(): void
    {
        foreach ([
            ['name_en' => '   ', 'name_en'],
            ['dob' => 'not a date', 'dob'],
            ['dob' => now()->addYear()->toDateString(), 'dob'],
            ['guardian_mobile' => '12345', 'guardian_mobile'],
            ['brn' => '2014123456789012', 'brn'],                        // 16 digits
            ['brn' => '2014123456789012X', 'brn'],
        ] as $case) {
            $field = array_pop($case);

            try {
                $this->admit($case);
                $this->fail("{$field} should have been refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertSame(0, StudentProfile::count());
    }

    public function test_class_is_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->registry->admit($this->data(['class_id' => null]), $this->admin);
    }

    public function test_a_failed_admission_leaves_nothing_behind(): void
    {
        $this->admit(['roll_number' => '1', 'name_en' => 'First Kid', 'dob' => '2013-01-01', 'guardian_mobile' => '01911111111']);

        try {
            $this->admit(['roll_number' => '1', 'name_en' => 'Second Kid', 'dob' => '2013-02-02', 'guardian_mobile' => '01922222222']);     // roll taken
        } catch (ValidationException) {
        }

        $this->assertSame(1, StudentProfile::count());                  // no orphan profile from the failed attempt
        $this->assertSame(1, Student::count());
    }

    // ------------------------------------------------------------------
    // BRN
    // ------------------------------------------------------------------

    public function test_a_brn_already_on_record_blocks_the_save_and_names_the_existing_student(): void
    {
        $first = $this->admit(['brn' => self::BRN_A]);

        $e = $this->duplicate(fn () => $this->admit(['name_en' => 'Somebody Else', 'dob' => '2012-01-01', 'guardian_mobile' => '01999999999', 'brn' => self::BRN_A]));

        $this->assertSame('brn', $e->kind);
        $this->assertFalse($e->overridable);
        $this->assertSame($first->profile_id, $e->match->id);
        $this->assertStringContainsString('Rahim Uddin', $e->getMessage());
        $this->assertSame($first->student_id, $e->summary()['student']['code']);
        $this->assertSame(1, StudentProfile::count());
    }

    public function test_nobody_can_override_a_brn_match_not_even_with_the_permission_and_a_reason(): void
    {
        $this->admit(['brn' => self::BRN_A]);

        $e = $this->duplicate(fn () => $this->admit(['brn' => self::BRN_A, 'name_en' => 'Twin'], $this->admin, 'Please let me'));

        $this->assertFalse($e->overridable);
        $this->assertSame(0, StudentDuplicateOverride::count());
        $this->assertSame(1, StudentProfile::count());
    }

    public function test_a_deleted_students_brn_still_blocks(): void
    {
        $s = $this->admit(['brn' => self::BRN_A]);
        $s->delete();

        $e = $this->duplicate(fn () => $this->admit(['brn' => self::BRN_A]));

        $this->assertTrue($e->summary()['student']['trashed']);
    }

    public function test_with_a_brn_the_name_check_is_not_applied(): void
    {
        $this->admit(['brn' => self::BRN_A]);

        // same name and birth date, but a different registration number: two different children
        $second = $this->admit(['brn' => self::BRN_B, 'roll_number' => '2']);

        $this->assertSame(2, StudentProfile::count());
        $this->assertNotSame(self::BRN_A, $second->profile->brn);
    }

    public function test_the_brn_is_unique_in_the_database_too(): void
    {
        $this->admit(['brn' => self::BRN_A]);
        $other = $this->admit(['name_en' => 'Karim Ali', 'dob' => '2011-01-01', 'guardian_mobile' => '01955555555', 'roll_number' => '9']);

        $this->expectException(QueryException::class);
        DB::table('student_profiles')->where('id', $other->profile_id)->update(['brn' => self::BRN_A]);
    }

    // ------------------------------------------------------------------
    // likely duplicates
    // ------------------------------------------------------------------

    public function test_the_same_name_and_birth_date_is_blocked_and_a_normal_user_cannot_override(): void
    {
        $this->admit();

        $e = $this->duplicate(fn () => $this->admit(['roll_number' => '2'], $this->teacher));

        $this->assertSame('likely', $e->kind);
        $this->assertFalse($e->overridable);

        // having a reason is not enough without the permission
        $e = $this->duplicate(fn () => $this->admit(['roll_number' => '2'], $this->teacher, 'They are twins'));
        $this->assertFalse($e->overridable);
        $this->assertSame(1, StudentProfile::count());
    }

    public function test_a_user_with_the_override_permission_is_told_they_may_continue_but_needs_a_reason(): void
    {
        $this->admit();

        $e = $this->duplicate(fn () => $this->admit(['roll_number' => '2']));
        $this->assertTrue($e->overridable);

        // blank or whitespace reasons do not count
        $this->duplicate(fn () => $this->admit(['roll_number' => '2'], $this->admin, '   '));

        $this->assertSame(1, StudentProfile::count());
    }

    public function test_an_override_saves_the_student_and_records_who_why_and_which_record(): void
    {
        $original = $this->admit();

        $twin = $this->admit(['roll_number' => '2'], $this->admin, 'Twin brother, different BRN not yet issued');

        $this->assertSame(2, StudentProfile::count());

        $o = StudentDuplicateOverride::firstOrFail();
        $this->assertSame($twin->profile_id, $o->profile_id);
        $this->assertSame($original->profile_id, $o->matched_profile_id);
        $this->assertSame($this->admin->id, $o->user_id);
        $this->assertSame('Twin brother, different BRN not yet issued', $o->reason);
        $this->assertSame('entry', $o->context);
        $this->assertGreaterThanOrEqual(85, $o->score);

        $log = AuditLog::forRecord($twin->profile)->where('event', 'duplicate_override')->firstOrFail();
        $this->assertSame($original->student_id, $log->changes['matched']);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_typos_word_order_and_phone_formatting_are_still_caught(): void
    {
        $this->admit(['name_en' => 'Rahim Uddin', 'dob' => '2014-05-02', 'guardian_mobile' => '01712345678']);

        $cases = [
            'typo'              => ['name_en' => 'Rahim Udin', 'dob' => '2014-05-02', 'guardian_mobile' => '01999999999'],
            'swapped words'     => ['name_en' => 'Uddin Rahim', 'dob' => '2014-05-02', 'guardian_mobile' => '01999999999'],
            'honorific + case'  => ['name_en' => 'MD. RAHIM UDDIN', 'dob' => '2014-05-02', 'guardian_mobile' => '01999999999'],
            'same mobile, other birth date' => ['name_en' => 'Rahim Uddin', 'dob' => '2013-01-01', 'guardian_mobile' => '+880 1712-345678'],
        ];

        foreach ($cases as $label => $case) {
            $e = $this->duplicate(fn () => $this->admit($case + ['roll_number' => '5'], $this->teacher));
            $this->assertSame('likely', $e->kind, $label);
        }
    }

    public function test_it_does_not_cry_wolf(): void
    {
        $this->admit(['name_en' => 'Rahim Uddin', 'dob' => '2014-05-02', 'guardian_mobile' => '01712345678']);

        $fine = [
            ['name_en' => 'Karim Ali', 'dob' => '2014-05-02', 'guardian_mobile' => '01712345678'],          // same birthday and family phone, a different name
            ['name_en' => 'Rahim Uddin', 'dob' => '2013-03-03', 'guardian_mobile' => '01888888888'],         // same name, nothing else
            ['name_en' => 'Rahim Uddin', 'dob' => null, 'guardian_mobile' => null],                          // nothing to compare against
        ];

        foreach ($fine as $i => $case) {
            $this->admit($case + ['roll_number' => (string) (10 + $i)]);
        }

        $this->assertSame(4, StudentProfile::count());
    }

    // ------------------------------------------------------------------
    // rolls
    // ------------------------------------------------------------------

    public function test_a_roll_is_unique_in_a_class_but_free_in_another_and_after_a_delete(): void
    {
        $a = $this->admit(['roll_number' => '5', 'name_en' => 'One', 'dob' => '2010-01-01', 'guardian_mobile' => '01911111111']);

        try {
            $this->admit(['roll_number' => '5', 'name_en' => 'Two', 'dob' => '2010-02-02', 'guardian_mobile' => '01922222222']);
            $this->fail('the roll was taken');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('One', $e->errors()['roll_number'][0]);
        }

        $other = $this->makeClass('Class 6', 'B');
        $this->admit(['roll_number' => '5', 'class_id' => $other->id, 'name_en' => 'Three', 'dob' => '2010-03-03', 'guardian_mobile' => '01933333333']);

        $a->delete();
        $this->admit(['roll_number' => '5', 'name_en' => 'Four', 'dob' => '2010-04-04', 'guardian_mobile' => '01944444444']);          // the deleted student's roll is free again

        $this->assertSame(2, Student::count());                             // Three and Four are live
        $this->assertSame(3, Student::withTrashed()->count());               // One is soft-deleted, not gone
    }

    public function test_blank_rolls_never_clash_and_the_database_refuses_a_duplicate_roll(): void
    {
        $this->admit(['name_en' => 'One', 'dob' => '2010-01-01', 'guardian_mobile' => '01911111111']);
        $second = $this->admit(['name_en' => 'Two', 'dob' => '2010-02-02', 'guardian_mobile' => '01922222222']);
        $this->assertNull($second->roll_number);

        $a = $this->admit(['roll_number' => '8', 'name_en' => 'Three', 'dob' => '2010-03-03', 'guardian_mobile' => '01933333333']);

        $this->expectException(QueryException::class);
        DB::table('students')->where('id', $second->id)->update(['roll_number' => $a->roll_number]);        // around the app
    }

    public function test_a_child_cannot_be_enrolled_twice_in_one_session(): void
    {
        $s = $this->admit();
        $other = $this->makeClass('Class 6', 'B');

        $this->expectException(ValidationException::class);
        $this->registry->enroll($s->profile, ['class_id' => $other->id]);
    }

    public function test_a_new_session_adds_an_enrollment_without_touching_the_old_one(): void
    {
        $old = $this->admit(['roll_number' => '3']);
        $next = $this->makeClass('Class 7', 'A', (int) $this->class->academic_year + 1);

        $new = $this->registry->enroll($old->profile, ['class_id' => $next->id, 'roll_number' => '1', 'promoted_from_id' => $old->id]);

        $this->assertSame($old->profile_id, $new->profile_id);
        $this->assertSame($old->student_id, $new->student_id);
        $this->assertSame($this->class->id, $old->fresh()->class_id);
        $this->assertSame('3', $old->fresh()->roll_number);
        $this->assertSame(2, $old->profile->enrollments()->count());
    }

    // ------------------------------------------------------------------
    // editing and protected fields
    // ------------------------------------------------------------------

    public function test_an_approver_changes_protected_fields_directly_and_the_history_records_it(): void
    {
        $s = $this->admit(['religion' => 'Islam']);
        StudentFieldHistory::query()->delete();
        $this->actingAs($this->admin);

        $r = $this->registry->update($s->profile, ['name_en' => 'Rahim Uddin Ahmed', 'dob' => '2014-05-03', 'religion' => 'Hindu'], $this->admin);

        $this->assertEqualsCanonicalizing(['name_en', 'dob', 'religion'], $r['applied']);
        $this->assertSame([], $r['requested']);
        $this->assertSame('Rahim Uddin Ahmed', $s->profile->fresh()->name_en);
        $this->assertSame('Rahim Uddin Ahmed', $s->fresh()->student_name);

        $h = StudentFieldHistory::where('profile_id', $s->profile_id)->get()->keyBy('field');
        $this->assertSame('Rahim Uddin', $h['name_en']->old_value);
        $this->assertSame('2014-05-02', $h['dob']->old_value);
        $this->assertSame('2014-05-03', $h['dob']->new_value);
        $this->assertSame($this->admin->id, $h['name_en']->changed_by);
        $this->assertSame('screen', $h['name_en']->source);
    }

    public function test_someone_who_may_only_request_changes_creates_a_request_and_nothing_is_applied(): void
    {
        $s = $this->admit();
        $clerk = $this->clerk(['students.request_change']);

        $r = $this->registry->update($s->profile, ['father_name' => 'Abdul Karim', 'dob' => '2014-06-06', 'religion' => 'Islam'], $clerk, 'Father\'s name was misspelt on admission');

        $this->assertSame(['religion'], $r['applied']);                          // the ordinary field is saved at once
        $this->assertCount(2, $r['requested']);
        $this->assertSame('Karim Uddin', $s->profile->fresh()->father_name);       // protected values untouched
        $this->assertSame('2014-05-02', $s->profile->fresh()->dob->toDateString());

        $req = StudentChangeRequest::where('field', 'father_name')->firstOrFail();
        $this->assertSame('pending', $req->status);
        $this->assertSame('Karim Uddin', $req->old_value);
        $this->assertSame('Abdul Karim', $req->new_value);
        $this->assertSame($clerk->id, $req->requested_by);
        $this->assertSame('Father\'s name was misspelt on admission', $req->reason);
    }

    public function test_a_request_needs_a_reason(): void
    {
        $s = $this->admit();
        $clerk = $this->clerk(['students.request_change']);

        try {
            $this->registry->update($s->profile, ['gender' => 'Female'], $clerk, '  ');
            $this->fail('a reason was required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('change_reason', $e->errors());
        }

        $this->assertSame(0, StudentChangeRequest::count());
    }

    public function test_someone_without_request_rights_cannot_touch_protected_fields_at_all(): void
    {
        $s = $this->admit();
        $clerk = $this->clerk();

        $this->registry->update($s->profile, ['religion' => 'Islam', 'blood_group' => 'O+'], $clerk);      // ordinary fields are fine

        $this->expectException(AuthorizationException::class);
        $this->registry->update($s->profile, ['name_en' => 'Changed'], $clerk, 'because');
    }

    public function test_nothing_happens_when_the_values_are_the_same(): void
    {
        $s = $this->admit();
        $clerk = $this->clerk();                                                        // may not even request changes

        $r = $this->registry->update($s->profile, ['name_en' => 'Rahim Uddin', 'dob' => '2014-05-02', 'father_name' => 'Karim Uddin'], $clerk);

        $this->assertSame([], $r['applied']);
        $this->assertSame([], $r['requested']);
    }

    public function test_an_approver_editing_a_name_into_a_duplicate_is_stopped_too(): void
    {
        $this->admit(['name_en' => 'Rahim Uddin', 'dob' => '2014-05-02']);
        $other = $this->admit(['name_en' => 'Karim Ali', 'dob' => '2014-05-02', 'guardian_mobile' => '01955555555', 'roll_number' => '2']);

        $e = $this->duplicate(fn () => $this->registry->update($other->profile, ['name_en' => 'Rahim Uddin'], $this->teacherWithApproval()));

        $this->assertSame('likely', $e->kind);
        $this->assertSame('Karim Ali', $other->profile->fresh()->name_en);
    }

    private function teacherWithApproval(): User
    {
        return $this->clerk(['students.approve_change']);
    }

    // ------------------------------------------------------------------
    // deciding requests
    // ------------------------------------------------------------------

    private function pendingRequest(string $field = 'father_name', string $new = 'Abdul Karim'): StudentChangeRequest
    {
        $s = $this->admit();
        $this->registry->update($s->profile, [$field => $new], $this->clerk(['students.request_change']), 'correction');

        return StudentChangeRequest::firstOrFail();
    }

    public function test_approving_applies_the_value_and_records_the_decision(): void
    {
        $req = $this->pendingRequest();

        $done = $this->registry->approve($req, $this->admin, 'Checked against the birth certificate');

        $this->assertSame('approved', $done->status);
        $this->assertSame($this->admin->id, $done->decided_by);
        $this->assertSame('Checked against the birth certificate', $done->decision_remark);
        $this->assertNotNull($done->decided_at);
        $this->assertSame('Abdul Karim', $req->profile->fresh()->father_name);
        $this->assertSame('Abdul Karim', Student::first()->father_name);                  // certificates and reports read the approved value

        $this->assertSame('approval', StudentFieldHistory::where('field', 'father_name')->latest('id')->value('source'));
    }

    public function test_rejecting_keeps_the_old_value_and_needs_a_remark(): void
    {
        $req = $this->pendingRequest();

        try {
            $this->registry->reject($req, $this->admin, ' ');
            $this->fail('a remark was required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('remark', $e->errors());
        }

        $done = $this->registry->reject($req, $this->admin, 'The certificate shows the old spelling');

        $this->assertSame('rejected', $done->status);
        $this->assertSame('Karim Uddin', $req->profile->fresh()->father_name);
    }

    public function test_only_an_approver_can_decide_and_a_request_is_decided_once(): void
    {
        $req = $this->pendingRequest();

        foreach ([fn () => $this->registry->approve($req, $this->clerk(['students.request_change'])),
                  fn () => $this->registry->reject($req, $this->teacher, 'no')] as $try) {
            try {
                $try();
                $this->fail('a non-approver decided');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame('pending', $req->fresh()->status);

        $this->registry->approve($req, $this->admin);

        $this->expectException(ValidationException::class);
        $this->registry->reject($req, $this->admin, 'too late');
    }

    public function test_approval_repeats_the_duplicate_check_for_names_dates_and_brn(): void
    {
        $a = $this->admit(['name_en' => 'Rahim Uddin', 'dob' => '2014-05-02', 'brn' => self::BRN_A]);
        $b = $this->admit(['name_en' => 'Karim Ali', 'dob' => '2012-02-02', 'guardian_mobile' => '01955555555', 'roll_number' => '2']);
        $requester = $this->clerk(['students.request_change']);

        // a BRN already used by someone else
        $this->registry->update($b->profile, ['brn' => self::BRN_A], $requester, 'BRN issued');
        $brn = StudentChangeRequest::where('field', 'brn')->firstOrFail();

        $e = $this->duplicate(fn () => $this->registry->approve($brn, $this->admin));
        $this->assertSame('brn', $e->kind);
        $this->assertSame('pending', $brn->fresh()->status);
        $this->assertNull($b->profile->fresh()->brn);

        // a name and a birth date that, together, collide with another student
        $this->registry->update($b->profile, ['name_en' => 'Rahim Uddin', 'dob' => '2014-05-02'], $requester, 'Wrongly entered');

        // the new name alone collides with nobody (the birth date is still 2012) ...
        $this->registry->approve(StudentChangeRequest::where('field', 'name_en')->firstOrFail(), $this->admin);
        $this->assertSame('Rahim Uddin', $b->profile->fresh()->name_en);

        // ... but the new birth date now makes the record a likely duplicate of the first student
        $dob = StudentChangeRequest::where('field', 'dob')->firstOrFail();
        $e = $this->duplicate(fn () => $this->registry->approve($dob, $this->teacherWithApproval()));

        $this->assertSame('likely', $e->kind);
        $this->assertSame('pending', $dob->fresh()->status);
        $this->assertSame('2012-02-02', $b->profile->fresh()->dob->toDateString());
    }

    public function test_a_likely_duplicate_at_approval_can_be_overridden_by_someone_allowed_with_a_reason(): void
    {
        $this->admit(['name_en' => 'Rahim Uddin', 'dob' => '2014-05-02']);
        $b = $this->admit(['name_en' => 'Karim Ali', 'dob' => '2014-05-02', 'guardian_mobile' => '01955555555', 'roll_number' => '2']);

        $this->registry->update($b->profile, ['name_en' => 'Rahim Uddin'], $this->clerk(['students.request_change']), 'Spelling');
        $req = StudentChangeRequest::firstOrFail();

        $this->duplicate(fn () => $this->registry->approve($req, $this->admin));                    // no reason given

        $this->registry->approve($req, $this->admin, 'Approved', 'Two different boys with one name; BRNs follow');

        $this->assertSame('Rahim Uddin', $b->profile->fresh()->name_en);
        $this->assertSame('change', StudentDuplicateOverride::firstOrFail()->context);
    }
}
