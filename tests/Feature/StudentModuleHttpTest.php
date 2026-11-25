<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentChangeRequest;
use App\Models\StudentDuplicateOverride;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\StudentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudentModuleHttpTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $class;
    private const BRN = '20141234567890123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class = $this->makeClass('Class 6', 'A');
    }

    private function form(array $over = []): array
    {
        return array_merge([
            'name_en' => 'Rahim Uddin', 'dob' => '2014-05-02', 'gender' => 'Male', 'guardian_mobile' => '01712345678', 'class_id' => $this->class->id,
        ], $over);
    }

    private function admit(array $over = []): Student
    {
        return app(StudentRegistry::class)->admit($this->form($over), $this->admin);
    }

    private function userWith(array $abilities, string $name = 'staff'): User
    {
        $slug = $name . '_' . substr(md5(json_encode($abilities)), 0, 6);
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'abilities' => $abilities, 'is_system' => false]);

        return $this->makeUser($slug);
    }

    // ------------------------------------------------------------------
    // list
    // ------------------------------------------------------------------

    public function test_the_list_shows_the_session_paginates_and_never_loads_everyone(): void
    {
        // distinct names, birth dates and mobiles: numbered names with equal birthdays would (rightly) be flagged as duplicates
        $names = ['Aaliya', 'Bashir', 'Chandra', 'Dilruba', 'Emon', 'Farhana', 'Golam', 'Habiba', 'Imran', 'Jahid', 'Kabir', 'Laila', 'Mizan', 'Nusrat', 'Omar',
                  'Parvin', 'Quasem', 'Rafiq', 'Sabina', 'Tanvir', 'Ulfat', 'Vabna', 'Wasim', 'Xenia', 'Yasin', 'Zahid', 'Anika', 'Belal', 'Chaity', 'Dulal'];
        foreach ($names as $i => $name) {
            $this->admit(['name_en' => $name, 'dob' => now()->subYears(12)->addDays($i)->toDateString(), 'guardian_mobile' => '0171' . str_pad((string) ($i + 1), 7, '0', STR_PAD_LEFT), 'roll_number' => (string) ($i + 1)]);
        }

        $this->actingAs($this->admin)->get('/student-module/students')->assertOk()
            ->assertInertia(fn ($p) => $p->component('StudentModule/Index')
                ->has('students.data', 25)                                   // a page, not the table
                ->where('students.total', 30)
                ->where('students.capped', false)
                ->where('students.links.2.url', fn ($u) => $u !== null)         // there is a next page
                ->has('sessions')->has('classes', 1)->has('statuses'));
    }

    public function test_the_list_is_built_with_a_fixed_number_of_queries(): void
    {
        $names = ['Aaliya', 'Bashir', 'Chandra', 'Dilruba', 'Emon', 'Farhana', 'Golam', 'Habiba', 'Imran', 'Jahid', 'Kabir', 'Laila', 'Mizan', 'Nusrat', 'Omar', 'Parvin', 'Quasem', 'Rafiq', 'Sabina', 'Tanvir'];
        foreach ($names as $i => $name) {
            $this->admit(['name_en' => $name, 'dob' => now()->subYears(11)->addDays($i)->toDateString(), 'guardian_mobile' => '0181' . str_pad((string) ($i + 1), 7, '0', STR_PAD_LEFT), 'roll_number' => (string) ($i + 1)]);
        }

        Cache::flush();
        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get('/student-module/students')->assertOk();
        $queries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(14, $queries, "the list ran {$queries} queries");        // a fixed handful, whatever the number of students
    }

    public function test_search_finds_by_code_name_start_and_guardian_mobile(): void
    {
        $a = $this->admit(['name_en' => 'Rahim Uddin', 'guardian_mobile' => '01712345678', 'roll_number' => '1']);
        $this->admit(['name_en' => 'Karim Ali', 'dob' => '2013-03-03', 'guardian_mobile' => '01899999999', 'roll_number' => '2']);

        $names = fn (string $q) => collect($this->actingAs($this->admin)->get('/student-module/students?search=' . urlencode($q))->viewData('page')['props']['students']['data'])->pluck('name')->all();

        $this->assertSame(['Rahim Uddin'], $names($a->student_id));              // by code
        $this->assertSame(['Rahim Uddin'], $names('rahim'));                      // by the start of the name
        $this->assertSame(['Rahim Uddin'], $names('+880 1712-345678'));           // by mobile, any formatting
        $this->assertSame([], $names('zzz'));
    }

    public function test_filters_by_class_status_gender_and_missing_brn(): void
    {
        $this->admit(['name_en' => 'With Brn', 'brn' => self::BRN, 'roll_number' => '1']);
        $b = $this->admit(['name_en' => 'No Brn', 'dob' => '2013-03-03', 'guardian_mobile' => '01899999999', 'gender' => 'Female', 'roll_number' => '2']);
        $b->update(['status' => 'transferred']);

        $get = fn (string $qs) => collect($this->actingAs($this->admin)->get("/student-module/students?{$qs}")->viewData('page')['props']['students']['data'])->pluck('name')->all();

        $this->assertSame(['No Brn'], $get('brn_missing=1'));
        $this->assertSame(['No Brn'], $get('gender=Female'));
        $this->assertSame(['No Brn'], $get('status=transferred'));
        $this->assertEqualsCanonicalizing(['With Brn', 'No Brn'], $get('class_id=' . $this->class->id));
    }

    public function test_the_brn_is_masked_in_the_list_unless_the_user_may_see_it(): void
    {
        $this->admit(['brn' => self::BRN]);
        $viewer = $this->userWith(['students.view'], 'viewer');

        $row = fn (User $u) => $this->actingAs($u)->get('/student-module/students')->viewData('page')['props']['students']['data'][0];

        $this->assertSame('2014' . str_repeat('•', 10) . '123', $row($viewer)['brn']);
        $this->assertSame(self::BRN, $row($this->admin)['brn']);

        // and the full number is nowhere in what the browser receives
        $this->assertStringNotContainsString(self::BRN, $this->actingAs($viewer)->get('/student-module/students')->getContent());
    }

    public function test_a_class_teacher_sees_only_their_own_class_and_cannot_open_anyone_elses_profile(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $mine = $this->makeClass('Class 7', 'A', teacher: $teacher);

        $own = app(StudentRegistry::class)->admit($this->form(['class_id' => $mine->id, 'name_en' => 'My Pupil']), $this->admin);
        $other = $this->admit(['name_en' => 'Not Mine', 'dob' => '2013-03-03', 'guardian_mobile' => '01899999999']);

        $names = collect($this->actingAs($teacher)->get('/student-module/students')->viewData('page')['props']['students']['data'])->pluck('name')->all();
        $this->assertSame(['My Pupil'], $names);

        $this->actingAs($teacher)->get("/student-module/students/{$own->profile_id}")->assertOk();
        $this->actingAs($teacher)->get("/student-module/students/{$other->profile_id}")->assertForbidden();                 // a guessed id is not enough
        $this->actingAs($teacher)->get("/student-module/students/{$other->profile_id}/edit")->assertForbidden();
        $this->actingAs($teacher)->delete("/student-module/students/{$other->profile_id}")->assertForbidden();
    }

    // ------------------------------------------------------------------
    // entry
    // ------------------------------------------------------------------

    public function test_student_entry_saves_and_opens_the_profile(): void
    {
        $r = $this->actingAs($this->admin)->post('/student-module/students', $this->form(['brn' => self::BRN, 'roll_number' => '4']));

        $student = Student::firstOrFail();
        $r->assertRedirect("/student-module/students/{$student->profile_id}")->assertSessionHas('success');
        $this->assertSame(self::BRN, $student->profile->brn);
        $this->assertSame('4', $student->roll_number);
    }

    public function test_entry_needs_the_permission_and_valid_input(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);                            // may view their class, not admit

        $this->actingAs($teacher)->post('/student-module/students', $this->form())->assertForbidden();
        $this->actingAs($teacher)->get('/student-module/students/create')->assertForbidden();
        $this->assertSame(0, Student::count());

        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['name_en' => '']))->assertSessionHasErrors('name_en');
        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['class_id' => 99999]))->assertSessionHasErrors('class_id');
        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['brn' => '123']))->assertSessionHasErrors('brn');
        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['gender' => 'Robot']))->assertSessionHasErrors('gender');
        $this->assertSame(0, Student::count());
    }

    public function test_a_brn_match_is_refused_and_the_response_names_the_existing_student(): void
    {
        $first = $this->admit(['brn' => self::BRN]);

        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['name_en' => 'Someone', 'dob' => '2012-01-01', 'guardian_mobile' => '01999999999', 'brn' => self::BRN, 'override_reason' => 'let me']))
            ->assertSessionHasErrors('duplicate')
            ->assertSessionHas('duplicate', fn ($d) => $d['kind'] === 'brn' && $d['overridable'] === false && $d['student']['code'] === $first->student_id);

        $this->assertSame(1, StudentProfile::count());
    }

    public function test_a_likely_duplicate_can_be_overridden_over_http_only_with_permission_and_reason(): void
    {
        $this->admit();

        // a user who may admit students but not override duplicates
        $clerk = $this->userWith(['students.view', 'students.create'], 'clerk');
        $this->actingAs($clerk)->post('/student-module/students', $this->form(['override_reason' => 'twin']))->assertSessionHasErrors('duplicate');
        $this->assertSame(1, StudentProfile::count());

        // the admin must still give a reason
        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['roll_number' => '2']))
            ->assertSessionHasErrors('duplicate')
            ->assertSessionHas('duplicate', fn ($d) => $d['kind'] === 'likely' && $d['overridable'] === true);

        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['roll_number' => '2', 'override_reason' => 'Twin brother']))->assertSessionHasNoErrors();

        $this->assertSame(2, StudentProfile::count());
        $this->assertSame('Twin brother', StudentDuplicateOverride::firstOrFail()->reason);
    }

    public function test_quick_entry_needs_only_the_essentials_and_applies_the_same_checks(): void
    {
        $quick = fn (array $over = []) => $this->actingAs($this->admin)->post('/student-module/students/quick', array_merge([
            'name_en' => 'Quick Kid', 'gender' => 'Female', 'guardian_mobile' => '01911111111', 'class_id' => $this->class->id, 'roll_number' => '9',
        ], $over));

        $quick()->assertSessionHasNoErrors();
        $this->assertSame('Quick Kid', Student::firstOrFail()->student_name);

        $quick(['name_en' => 'Next One', 'guardian_mobile' => '01922222222', 'roll_number' => '10', 'another' => true])
            ->assertRedirect("/student-module/students/quick?class_id={$this->class->id}");            // stays on the form for the next child

        $quick(['gender' => null])->assertSessionHasErrors('gender');
        $quick(['guardian_mobile' => null])->assertSessionHasErrors('guardian_mobile');

        // a BRN match is caught here too
        $this->admit(['name_en' => 'Has Brn', 'dob' => '2012-02-02', 'guardian_mobile' => '01955555555', 'brn' => self::BRN, 'roll_number' => '20']);
        $quick(['name_en' => 'Another', 'guardian_mobile' => '01933333333', 'roll_number' => '21', 'brn' => self::BRN])->assertSessionHasErrors('duplicate');
    }

    public function test_a_taken_roll_is_a_field_error(): void
    {
        $this->admit(['roll_number' => '5', 'name_en' => 'Holder']);

        $this->actingAs($this->admin)->post('/student-module/students', $this->form(['name_en' => 'Other', 'dob' => '2013-03-03', 'guardian_mobile' => '01899999999', 'roll_number' => '5']))
            ->assertSessionHasErrors('roll_number');
    }

    // ------------------------------------------------------------------
    // profile, edit, delete
    // ------------------------------------------------------------------

    public function test_the_profile_shows_enrollments_history_and_masks_the_brn_in_the_history_too(): void
    {
        $s = $this->admit(['brn' => self::BRN]);
        $this->actingAs($this->admin);
        $s->profile->update(['brn' => '20149999999999999', 'religion' => 'Islam']);

        $viewer = $this->userWith(['students.view'], 'viewer');
        $props = $this->actingAs($viewer)->get("/student-module/students/{$s->profile_id}")->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['enrollments']);
        $this->assertSame('2014' . str_repeat('•', 10) . '999', $props['profile']['brn']);
        $brnLine = collect($props['histories'])->firstWhere('field', 'brn');
        $this->assertSame('2014' . str_repeat('•', 10) . '123', $brnLine['old']);          // masked in the history too
        $this->assertStringNotContainsString('20149999999999999', json_encode($props));
        $this->assertStringNotContainsString(self::BRN, json_encode($props));

        $full = $this->actingAs($this->admin)->get("/student-module/students/{$s->profile_id}")->viewData('page')['props'];
        $this->assertSame('20149999999999999', $full['profile']['brn']);
    }

    public function test_editing_applies_ordinary_fields_and_sends_protected_ones_for_approval(): void
    {
        $s = $this->admit();
        $requester = $this->userWith(['students.view', 'students.edit', 'students.request_change'], 'requester');

        $this->actingAs($requester)->put("/student-module/students/{$s->profile_id}", $this->form([
            'religion' => 'Islam', 'father_name' => 'Abdul Karim', 'change_reason' => 'Misspelt at admission',
        ]))->assertSessionHas('success', fn ($m) => str_contains($m, '1 change'));

        $p = $s->profile->fresh();
        $this->assertSame('Islam', $p->religion);                                 // saved at once
        $this->assertNull($p->father_name);                                       // protected: waiting
        $this->assertSame('Abdul Karim', StudentChangeRequest::firstOrFail()->new_value);
    }

    public function test_a_user_who_may_not_request_changes_is_told_so_and_nothing_changes(): void
    {
        $s = $this->admit();
        $editor = $this->userWith(['students.view', 'students.edit'], 'editor');

        $this->actingAs($editor)->put("/student-module/students/{$s->profile_id}", $this->form(['name_en' => 'Changed Name']))->assertSessionHasErrors('protected');

        $this->assertSame('Rahim Uddin', $s->profile->fresh()->name_en);
        $this->assertSame(0, StudentChangeRequest::count());
    }

    public function test_the_admin_edits_protected_fields_directly(): void
    {
        $s = $this->admit();

        $this->actingAs($this->admin)->put("/student-module/students/{$s->profile_id}", $this->form(['name_en' => 'Rahim Uddin Ahmed']))->assertSessionHasNoErrors();

        $this->assertSame('Rahim Uddin Ahmed', $s->profile->fresh()->name_en);
        $this->assertSame('Rahim Uddin Ahmed', $s->fresh()->student_name);
    }

    public function test_the_edit_form_never_contains_a_masked_brn_that_could_be_saved_back(): void
    {
        $s = $this->admit(['brn' => self::BRN]);
        $editor = $this->userWith(['students.view', 'students.edit'], 'editor');

        $profile = $this->actingAs($editor)->get("/student-module/students/{$s->profile_id}/edit")->viewData('page')['props']['profile'];

        $this->assertNull($profile['brn']);
        $this->assertSame('2014' . str_repeat('•', 10) . '123', $profile['brn_masked']);
    }

    public function test_deleting_is_only_for_a_mistaken_entry(): void
    {
        $s = $this->admit();
        DB::table('attendances')->insert(['student_id' => $s->id, 'class_id' => $this->class->id, 'attendance_date' => now()->toDateString(), 'status' => 'present', 'academic_year' => (string) $this->class->academic_year, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin)->delete("/student-module/students/{$s->profile_id}")->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot be deleted'));
        $this->assertNotNull(StudentProfile::find($s->profile_id));

        $clean = $this->admit(['name_en' => 'Mistake', 'dob' => '2013-03-03', 'guardian_mobile' => '01899999999', 'roll_number' => '2']);
        $this->actingAs($this->admin)->delete("/student-module/students/{$clean->profile_id}")->assertRedirect('/student-module/students');

        $this->assertNull(StudentProfile::find($clean->profile_id));
        $this->assertNotNull(StudentProfile::withTrashed()->find($clean->profile_id));        // soft-deleted
    }

    // ------------------------------------------------------------------
    // change requests
    // ------------------------------------------------------------------

    private function pending(): StudentChangeRequest
    {
        $s = $this->admit(['brn' => self::BRN]);
        $requester = $this->userWith(['students.view', 'students.edit', 'students.request_change'], 'requester');
        app(StudentRegistry::class)->update($s->profile, ['father_name' => 'Abdul Karim', 'brn' => '20149999999999999'], $requester, 'Correction');

        return StudentChangeRequest::where('field', 'father_name')->firstOrFail();
    }

    public function test_approvers_see_every_request_and_requesters_only_their_own(): void
    {
        $this->pending();
        $other = $this->userWith(['students.view', 'students.edit', 'students.request_change'], 'other_requester');
        $s = $this->admit(['name_en' => 'Second', 'dob' => '2013-03-03', 'guardian_mobile' => '01899999999', 'roll_number' => '2']);
        app(StudentRegistry::class)->update($s->profile, ['mother_name' => 'Rahima'], $other, 'Typo');

        $count = fn (User $u) => count($this->actingAs($u)->get('/student-module/change-requests')->viewData('page')['props']['requests']['data']);

        $this->assertSame(3, $count($this->admin));                      // father, brn, mother
        $this->assertSame(1, $count($other));
        $this->actingAs($this->userWith(['students.view'], 'nobody'))->get('/student-module/change-requests')->assertForbidden();
    }

    public function test_the_page_masks_a_brn_change_for_someone_who_may_not_see_it(): void
    {
        $this->pending();
        $requester = User::where('role', 'like', 'requester_%')->firstOrFail();

        $rows = $this->actingAs($requester)->get('/student-module/change-requests')->viewData('page')['props']['requests']['data'];
        $brn = collect($rows)->firstWhere('field', 'brn');

        $this->assertSame('2014' . str_repeat('•', 10) . '123', $brn['old']);
        $this->assertSame('2014' . str_repeat('•', 10) . '999', $brn['new']);
    }

    public function test_approving_and_rejecting_over_http(): void
    {
        $req = $this->pending();
        $brn = StudentChangeRequest::where('field', 'brn')->firstOrFail();

        $this->actingAs($this->admin)->post("/student-module/change-requests/{$req->id}/approve", ['remark' => 'Verified'])->assertSessionHas('success');
        $this->assertSame('Abdul Karim', $req->profile->fresh()->father_name);

        $this->actingAs($this->admin)->post("/student-module/change-requests/{$brn->id}/reject", ['remark' => ''])->assertSessionHasErrors('remark');
        $this->actingAs($this->admin)->post("/student-module/change-requests/{$brn->id}/reject", ['remark' => 'Certificate shows the old number'])->assertSessionHas('success');
        $this->assertSame('rejected', $brn->fresh()->status);
    }

    public function test_only_an_approver_can_decide(): void
    {
        $req = $this->pending();
        $requester = User::where('role', 'like', 'requester_%')->firstOrFail();

        $this->actingAs($requester)->post("/student-module/change-requests/{$req->id}/approve")->assertForbidden();
        $this->actingAs($requester)->post("/student-module/change-requests/{$req->id}/reject", ['remark' => 'no'])->assertForbidden();
        $this->assertSame('pending', $req->fresh()->status);
    }

    public function test_an_approval_that_would_create_a_duplicate_is_blocked_with_the_summary(): void
    {
        $this->admit(['brn' => self::BRN]);
        $b = $this->admit(['name_en' => 'Second', 'dob' => '2013-03-03', 'guardian_mobile' => '01899999999', 'roll_number' => '2']);
        $requester = $this->userWith(['students.view', 'students.edit', 'students.request_change'], 'requester');
        app(StudentRegistry::class)->update($b->profile, ['brn' => self::BRN], $requester, 'BRN issued');
        $req = StudentChangeRequest::firstOrFail();

        $this->actingAs($this->admin)->post("/student-module/change-requests/{$req->id}/approve")
            ->assertSessionHasErrors('duplicate')
            ->assertSessionHas('duplicate', fn ($d) => $d['kind'] === 'brn' && $d['request_id'] === $req->id);

        $this->assertNull($b->profile->fresh()->brn);
    }

    // ------------------------------------------------------------------
    // language and caching
    // ------------------------------------------------------------------

    public function test_the_language_can_be_switched_and_the_labels_follow(): void
    {
        $en = $this->actingAs($this->admin)->get('/student-module/students')->viewData('page')['props'];
        $this->assertSame('Student Entry', $en['i18n']['student']['entry']);

        $this->actingAs($this->admin)->post('/locale', ['locale' => 'bn'])->assertSessionHas('locale', 'bn');

        $bn = $this->actingAs($this->admin)->get('/student-module/students')->viewData('page')['props'];
        $this->assertSame('bn', $bn['locale']);
        $this->assertSame('শিক্ষার্থী এন্ট্রি', $bn['i18n']['student']['entry']);

        $this->actingAs($this->admin)->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    public function test_both_languages_have_exactly_the_same_keys(): void
    {
        $flat = function (array $a, string $prefix = '') use (&$flat) {
            return collect($a)->flatMap(fn ($v, $k) => is_array($v) ? $flat($v, "{$prefix}{$k}.") : ["{$prefix}{$k}" => true])->keys()->sort()->values()->all();
        };

        $this->assertSame($flat(trans('student', [], 'en')), $flat(trans('student', [], 'bn')));
    }

    public function test_translations_are_sent_only_on_the_student_module_pages(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($p) => $p->where('i18n', null));
    }

    public function test_a_new_class_appears_in_the_cached_dropdown_at_once(): void
    {
        $count = fn () => count($this->actingAs($this->admin)->get('/student-module/students')->viewData('page')['props']['classes']);

        $this->assertSame(1, $count());                       // warms the cache

        $this->makeClass('Class 7', 'A');

        $this->assertSame(2, $count());                       // not stale
    }
}
