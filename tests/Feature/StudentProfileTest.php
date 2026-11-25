<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentFieldHistory;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Normalize;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private const SENSITIVE = [
        'override_duplicate', 'override_dues', 'request_transfer', 'approve_transfer', 'reinstate_student',
        'request_change', 'approve_change', 'import_students', 'promote_students', 'issue_certificate', 'view_full_brn',
    ];

    // ------------------------------------------------------------------
    // identity vs enrollment
    // ------------------------------------------------------------------

    public function test_every_enrollment_belongs_to_a_profile_holding_the_childs_identity(): void
    {
        $class = $this->makeClass('Class 6', 'A');
        $s = $this->makeStudent($class, 'Rahim Uddin', ['date_of_birth' => '2014-05-02', 'father_name' => 'Karim', 'guardian_phone' => '01712345678']);

        $p = $s->profile;

        $this->assertNotNull($p);
        $this->assertSame($s->student_id, $p->code);
        $this->assertSame('Rahim Uddin', $p->name_en);
        $this->assertSame('2014-05-02', $p->dob->toDateString());
        $this->assertSame('Karim', $p->father_name);
        $this->assertSame('1712345678', $p->guardian_mobile_norm);
        $this->assertSame('rahim uddin', $p->name_normalized);
    }

    public function test_a_second_session_shares_the_same_profile_and_never_overwrites_the_first(): void
    {
        $a = $this->makeStudent($this->makeClass('Class 6', 'A', 2026), 'Rahim Uddin', ['academic_year' => 2026, 'student_id' => '26100001']);
        $b = $this->makeStudent($this->makeClass('Class 7', 'A', 2027), 'Rahim Uddin', ['academic_year' => 2027, 'student_id' => '26100001', 'promoted_from_id' => $a->id]);

        $this->assertSame($a->profile_id, $b->profile_id);
        $this->assertSame(1, StudentProfile::count());
        $this->assertSame(2, $a->profile->enrollments()->count());
        $this->assertSame(2026, (int) $a->fresh()->academic_year);          // the old enrollment is untouched
        $this->assertSame($b->id, $a->profile->latestEnrollment->id);
    }

    public function test_editing_a_personal_field_on_an_enrollment_updates_the_profile_and_every_other_enrollment(): void
    {
        $a = $this->makeStudent($this->makeClass('Class 6', 'A', 2026), 'Rahim Uddin', ['academic_year' => 2026, 'student_id' => '26100001']);
        $b = $this->makeStudent($this->makeClass('Class 7', 'A', 2027), 'Rahim Uddin', ['academic_year' => 2027, 'student_id' => '26100001']);

        $b->update(['father_name' => 'Abdul Karim']);

        $this->assertSame('Abdul Karim', $a->profile->fresh()->father_name);
        $this->assertSame('Abdul Karim', $a->fresh()->father_name);          // the other session's copy follows
    }

    public function test_editing_the_profile_keeps_the_enrollment_copies_in_step(): void
    {
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Rahim Uddin');

        $s->profile->update(['name_en' => 'Md. Rahim Uddin', 'gender' => 'Male']);

        $this->assertSame('Md. Rahim Uddin', $s->fresh()->student_name);
        $this->assertSame('Male', $s->fresh()->gender);
        $this->assertSame('rahim uddin', $s->profile->fresh()->name_normalized);        // "Md." is not part of the identity
    }

    // ------------------------------------------------------------------
    // history
    // ------------------------------------------------------------------

    public function test_every_changed_field_is_written_to_the_history_with_who_and_where_from(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Rahim Uddin', ['father_name' => 'Karim']);
        StudentFieldHistory::query()->delete();

        $this->actingAs($admin);
        $s->profile->update(['father_name' => 'Abdul Karim', 'religion' => 'Islam']);

        $h = StudentFieldHistory::where('profile_id', $s->profile_id)->get()->keyBy('field');

        $this->assertEqualsCanonicalizing(['father_name', 'religion'], $h->keys()->all());
        $this->assertSame('Karim', $h['father_name']->old_value);
        $this->assertSame('Abdul Karim', $h['father_name']->new_value);
        $this->assertNull($h['religion']->old_value);
        $this->assertSame($admin->id, $h['father_name']->changed_by);
        $this->assertSame('screen', $h['father_name']->source);
    }

    public function test_the_source_of_a_change_can_be_named(): void
    {
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Rahim Uddin');

        StudentProfile::inSource('import', fn () => $s->profile->update(['religion' => 'Hindu']));
        $s->profile->fresh()->update(['religion' => 'Buddhist']);                      // back to the default afterwards

        $this->assertSame(['import', 'screen'], StudentFieldHistory::where('field', 'religion')->orderBy('id')->pluck('source')->all());
    }

    public function test_nothing_is_logged_when_nothing_changed(): void
    {
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Rahim Uddin');
        StudentFieldHistory::query()->delete();

        $s->profile->update(['name_en' => 'Rahim Uddin']);

        $this->assertSame(0, StudentFieldHistory::count());
    }

    // ------------------------------------------------------------------
    // BRN
    // ------------------------------------------------------------------

    public function test_a_brn_can_only_belong_to_one_student_at_the_database_level(): void
    {
        $a = $this->makeStudent($this->makeClass('Class 6', 'A'), 'First')->profile;
        $b = $this->makeStudent($this->makeClass('Class 6', 'B'), 'Second')->profile;

        $a->update(['brn' => '20141234567890123']);

        $this->expectException(QueryException::class);
        DB::table('student_profiles')->where('id', $b->id)->update(['brn' => '20141234567890123']);      // bypasses every check in the app
    }

    public function test_many_students_may_have_no_brn(): void
    {
        $class = $this->makeClass('Class 6', 'A');
        $this->makeStudent($class, 'One');
        $this->makeStudent($class, 'Two');
        $this->makeStudent($class, 'Three');

        $this->assertSame(3, StudentProfile::whereNull('brn')->count());
        $this->assertTrue(StudentProfile::first()->brn_missing);
    }

    public function test_brn_is_masked_unless_the_user_may_see_it_all(): void
    {
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Rahim Uddin');
        $s->profile->update(['brn' => '2014-1234-5678-9012-3']);                       // typed with dashes

        $this->assertSame('20141234567890123', $s->profile->fresh()->brn);            // stored as digits only

        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->assertSame('2014' . str_repeat('•', 10) . '123', $s->profile->fresh()->brnFor($teacher));
        $this->assertSame('20141234567890123', $s->profile->fresh()->brnFor($admin));
    }

    public function test_the_full_brn_never_reaches_the_audit_log(): void
    {
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Rahim Uddin');
        $s->profile->update(['brn' => '20141234567890123']);

        $this->assertStringNotContainsString('20141234567890123', json_encode(AuditLog::all()->toArray()));
        $this->assertSame(1, StudentFieldHistory::where('field', 'brn')->count());        // but the history, which is permissioned, has it
    }

    // ------------------------------------------------------------------
    // soft delete
    // ------------------------------------------------------------------

    public function test_deleting_the_last_enrollment_soft_deletes_the_profile_and_it_can_come_back(): void
    {
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Mistake');
        $id = $s->id;
        $profileId = $s->profile_id;

        $s->delete();

        $this->assertNull(Student::find($id));
        $this->assertNotNull(Student::withTrashed()->find($id));                      // nothing is hard-deleted
        $this->assertNull(StudentProfile::find($profileId));
        $this->assertNotNull(StudentProfile::withTrashed()->find($profileId));

        Student::withTrashed()->find($id)->restore();

        $this->assertNotNull(StudentProfile::find($profileId));
    }

    public function test_deleting_one_of_several_enrollments_keeps_the_profile(): void
    {
        $a = $this->makeStudent($this->makeClass('Class 6', 'A', 2026), 'Rahim', ['academic_year' => 2026, 'student_id' => '26100001']);
        $this->makeStudent($this->makeClass('Class 7', 'A', 2027), 'Rahim', ['academic_year' => 2027, 'student_id' => '26100001']);

        $a->delete();

        $this->assertNotNull(StudentProfile::find($a->profile_id));
    }

    public function test_a_student_code_is_never_reused_after_a_delete(): void
    {
        $s = $this->makeStudent($this->makeClass('Class 6', 'A'), 'Mistake', ['student_id' => null]);
        $code = $s->student_id;
        $s->delete();

        $next = Student::create(['student_name' => 'Next', 'academic_year' => (int) now()->year, 'class_id' => $s->class_id, 'admission_date' => now()->toDateString()]);

        $this->assertNotSame($code, $next->student_id);
    }

    // ------------------------------------------------------------------
    // helpers and permissions
    // ------------------------------------------------------------------

    public function test_normalizers(): void
    {
        $this->assertSame('rahim uddin', Normalize::name('  Md.  RAHIM-Uddin '));
        $this->assertSame('fatema khatun', Normalize::name('Mst. Fatema Khatun'));
        $this->assertSame('রহিম উদ্দিন', Normalize::name('রহিম উদ্দিন'));            // Bangla letters and their marks are kept as they are

        $this->assertSame('1712345678', Normalize::mobile('+880 1712-345678'));
        $this->assertSame('1712345678', Normalize::mobile('01712345678'));
        $this->assertSame('1712345678', Normalize::mobile('8801712345678'));
        $this->assertNull(Normalize::mobile(''));

        $this->assertSame('20141234567890123', Normalize::brn('2014 1234 5678 9012 3'));
        $this->assertNull(Normalize::brn('  '));
        $this->assertTrue(Normalize::isValidBrn('20141234567890123'));
        $this->assertFalse(Normalize::isValidBrn('2014123456789012'));                // 16 digits
        $this->assertFalse(Normalize::isValidBrn('2014123456789012A'));
        $this->assertFalse(Normalize::isValidBrn(null));

        $this->assertSame('2014' . str_repeat('•', 10) . '123', Normalize::maskBrn('20141234567890123'));
        $this->assertNull(Normalize::maskBrn(null));

        $this->assertSame(100.0, Normalize::similarity('rahim uddin', 'uddin rahim'));          // word order does not matter
        $this->assertGreaterThan(85, Normalize::similarity('rahim uddin', 'rahim udin'));        // a typo still matches
        $this->assertLessThan(60, Normalize::similarity('rahim uddin', 'karim ali'));
    }

    public function test_every_sensitive_permission_exists_and_only_admin_holds_it_by_default(): void
    {
        $catalogue = collect(config('abilities.groups'))->collapse();
        $admin = $this->makeUser(User::ROLE_ADMIN);

        foreach (self::SENSITIVE as $name) {
            $key = "students.{$name}";

            $this->assertTrue($catalogue->has($key), "{$key} is missing from the catalogue");
            $this->assertTrue($admin->canDo($key), "admin lacks {$key}");

            foreach (Role::where('slug', '!=', 'admin')->get() as $role) {
                $this->assertNotContains($key, $role->abilities, "{$role->slug} must not hold {$key} by default");
                $this->assertNotContains("{$key}:own", $role->abilities);
            }
        }
    }

    public function test_the_accountant_role_is_seeded_with_fee_work_and_no_student_edit_rights(): void
    {
        $role = Role::where('slug', 'accountant')->firstOrFail();

        $this->assertTrue($role->is_system);
        $this->assertContains('fees.collect', $role->abilities);
        $this->assertContains('students.admission_payments', $role->abilities);
        $this->assertNotContains('students.edit', $role->abilities);
        $this->assertNotContains('students.delete', $role->abilities);
    }

    public function test_a_sensitive_permission_can_be_given_to_another_role_from_the_settings(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $role = Role::where('slug', 'accountant')->firstOrFail();

        $this->actingAs($admin)->put("/roles/{$role->id}", [
            'name' => 'Accountant', 'abilities' => array_merge($role->abilities, ['students.view_full_brn']),
        ])->assertSessionHas('success');

        $this->assertTrue($this->makeUser('accountant')->canDo('students.view_full_brn'));
        $this->assertFalse($this->makeUser('teacher')->canDo('students.view_full_brn'));
    }
}
