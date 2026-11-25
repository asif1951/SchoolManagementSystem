<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateStudentException;
use App\Models\AdmissionFee;
use App\Models\AdmissionPayment;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\OnlineApplication;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentTransfer;
use App\Models\User;
use App\Services\AdmissionPayments;
use App\Services\CertificateService;
use App\Services\DuesService;
use App\Services\OnlineAdmissionService;
use App\Services\StudentRegistry;
use App\Services\TransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class AdmissionTransferCertificateTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $class;
    private string $year;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class = $this->makeClass('Class 6', 'A');
        $this->year = (string) $this->class->academic_year;
    }

    private function kid(string $name = 'Rahim Uddin', ?SchoolClass $class = null): Student
    {
        $this->n++;

        return app(StudentRegistry::class)->admit([
            'name_en' => $name . ' ' . chr(64 + $this->n), 'dob' => now()->subYears(11)->addDays($this->n)->toDateString(),
            'guardian_mobile' => '0171' . str_pad((string) $this->n, 7, '0', STR_PAD_LEFT), 'class_id' => ($class ?? $this->class)->id, 'roll_number' => (string) $this->n,
        ], $this->admin);
    }

    private function user(array $abilities, string $name = 'staff'): User
    {
        $slug = $name . '_' . substr(md5(json_encode($abilities)), 0, 6);
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'abilities' => $abilities, 'is_system' => false]);

        return $this->makeUser($slug);
    }

    private function pay(): AdmissionPayments
    {
        return app(AdmissionPayments::class);
    }

    private function fee(float $amount = 1000, ?string $class = null): void
    {
        $this->pay()->setFees($this->year, [$class ?? $this->class->class_name => $amount]);
    }

    // ------------------------------------------------------------------
    // admission payments
    // ------------------------------------------------------------------

    public function test_a_payment_gets_a_receipt_number_that_counts_up_and_is_never_reused(): void
    {
        $this->fee(1000);
        $a = $this->kid();
        $b = $this->kid();
        $yy = substr($this->year, 2);

        $p1 = $this->pay()->record($a, 400, 'cash', null, null, $this->admin);
        $p2 = $this->pay()->record($b, 1000, 'bkash', 'TXN123', 'in full', $this->admin);

        $this->assertSame("AD-{$yy}-000001", $p1->receipt_no);
        $this->assertSame("AD-{$yy}-000002", $p2->receipt_no);
        $this->assertSame('TXN123', $p2->reference);
        $this->assertSame($this->admin->id, $p2->collected_by);

        $this->pay()->void($p2, $this->admin, 'Entered against the wrong student');
        $p3 = $this->pay()->record($b, 1000, 'cash', null, null, $this->admin);

        $this->assertSame("AD-{$yy}-000003", $p3->receipt_no);                              // 000002 stays with the void receipt
        $this->assertNotNull(AdmissionPayment::find($p2->id));                              // nothing is deleted
    }

    public function test_payments_are_validated(): void
    {
        $this->fee(1000);
        $s = $this->kid();

        $bad = [
            ['amount' => 0, 'method' => 'cash', 'field' => 'amount'],
            ['amount' => -50, 'method' => 'cash', 'field' => 'amount'],
            ['amount' => 100, 'method' => 'cheque', 'field' => 'method'],
            ['amount' => 1500, 'method' => 'cash', 'field' => 'amount'],                     // more than is due
        ];

        foreach ($bad as $case) {
            try {
                $this->pay()->record($s, $case['amount'], $case['method'], null, null, $this->admin);
                $this->fail("{$case['field']} should have been refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($case['field'], $e->errors());
            }
        }

        $this->assertSame(0, AdmissionPayment::count());

        $this->pay()->record($s, 600, 'cash', null, null, $this->admin);
        $this->expectException(ValidationException::class);
        $this->pay()->record($s, 500, 'cash', null, null, $this->admin);                    // only 400 is left
    }

    public function test_a_voided_receipt_stops_counting_and_needs_a_reason(): void
    {
        $this->fee(1000);
        $s = $this->kid();
        $p = $this->pay()->record($s, 1000, 'cash', null, null, $this->admin);
        $this->assertSame(0.0, app(DuesService::class)->for($s)['total']);

        try {
            $this->pay()->void($p, $this->admin, '  ');
            $this->fail('a reason is needed');
        } catch (ValidationException) {
        }

        $this->pay()->void($p, $this->admin, 'Cheque bounced');

        $this->assertSame(1000.0, app(DuesService::class)->for($s)['total']);                // owes again
        $this->assertSame('Cheque bounced', $p->fresh()->void_reason);

        $this->expectException(ValidationException::class);                                  // cannot be voided twice
        $this->pay()->void($p, $this->admin, 'again');
    }

    public function test_the_summary_adds_up_by_method_class_and_date(): void
    {
        $this->makeClass('Class 7', 'A');
        $this->pay()->setFees($this->year, ['Class 6' => 1000, 'Class 7' => 2000]);

        $a = $this->kid('Alpha');                                                              // class 6, pays 1000 cash
        $b = $this->kid('Bravo');                                                              // class 6, pays 400 bKash
        $c = $this->kid('Charlie', SchoolClass::where('class_name', 'Class 7')->first());      // class 7, pays nothing

        $this->pay()->record($a, 1000, 'cash', null, null, $this->admin, now()->subDays(10));
        $this->pay()->record($b, 400, 'bkash', 'T1', null, $this->admin, now()->subDay());

        $all = $this->pay()->summary($this->year);

        $this->assertSame([4000.0, 1400.0, 2600.0], [$all['expected'], $all['collected'], $all['due']]);
        $this->assertSame(['cash' => 1000.0, 'bkash' => 400.0], array_map(fn ($m) => $m['total'], $all['by_method']));
        $this->assertSame('Class 6', $all['by_class'][0]['class']);
        $this->assertSame([2, 2000.0, 1400.0, 600.0], [$all['by_class'][0]['students'], $all['by_class'][0]['expected'], $all['by_class'][0]['collected'], $all['by_class'][0]['due']]);
        $this->assertSame('Class 7', $all['by_class'][1]['class']);
        $this->assertSame(2000.0, $all['by_class'][1]['due']);

        // the students with dues, the biggest first
        $this->assertSame([$c->id, $b->id], array_column($all['with_dues'], 'id'));
        $this->assertSame(600.0, $all['with_dues'][1]['due']);

        // a date range counts only the receipts inside it
        $recent = $this->pay()->summary($this->year, null, now()->subDays(3)->toDateString(), now()->toDateString());
        $this->assertSame(400.0, $recent['collected']);

        // one method, one class
        $this->assertSame(1000.0, $this->pay()->summary($this->year, null, null, null, 'cash')['collected']);
        $this->assertSame(2000.0, $this->pay()->summary($this->year, 'Class 7')['expected']);
        $this->assertSame(1400.0, $this->pay()->summary($this->year, 'Class 6')['collected']);
    }

    public function test_dues_also_count_the_school_fee_structures_charged_once_a_year(): void
    {
        $s = $this->kid();
        DB::table('fee_structures')->insert([
            ['class_id' => $this->class->id, 'academic_year' => $this->year, 'fee_name' => 'Annual', 'amount' => 3000, 'cycle' => 'yearly', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['class_id' => $this->class->id, 'academic_year' => $this->year, 'fee_name' => 'Tuition', 'amount' => 500, 'cycle' => 'monthly', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $annual = DB::table('fee_structures')->where('fee_name', 'Annual')->value('id');

        $dues = app(DuesService::class)->for($s);
        $this->assertSame(3000.0, $dues['total']);                                           // the monthly fee is not counted
        $this->assertSame('School fees', $dues['items'][0]['label']);

        DB::table('student_fee_policies')->insert(['student_id' => $s->id, 'fee_structure_id' => $annual, 'academic_year' => $this->year, 'discount_type' => 'percent', 'discount_value' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fee_payments')->insert(['student_id' => $s->id, 'academic_year' => $this->year, 'amount' => 1200, 'collected_by' => $this->admin->id, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(1500.0, app(DuesService::class)->for($s)['total']);                // 3000 − 10% − 1200
    }

    // ------------------------------------------------------------------
    // transfers
    // ------------------------------------------------------------------

    private function transfers(): TransferService
    {
        return app(TransferService::class);
    }

    public function test_a_transfer_with_no_dues_is_cleared_at_once_and_approval_issues_the_certificate(): void
    {
        $s = $this->kid();

        $t = $this->transfers()->request($s, $this->admin, 'Family is moving', 'City School');
        $this->assertSame('cleared', $t->status);
        $this->assertNotNull($t->cleared_at);

        $done = $this->transfers()->approve($t, $this->admin);

        $this->assertSame('issued', $done->status);
        $this->assertSame('TC-' . now()->year . '-0001', $done->certificate->serial);
        $this->assertSame('transfer', $done->certificate->type);
        $this->assertSame('Transferred to City School', $done->certificate->remarks);

        $s->refresh();
        $this->assertSame('transferred', $s->status);
        $this->assertSame(now()->toDateString(), $s->status_changed_on->toDateString());

        // the trail: who and when for each step
        $this->assertSame($this->admin->id, $done->requested_by);
        $this->assertNotNull($done->requested_at);
        $this->assertNotNull($done->cleared_at);
        $this->assertSame($this->admin->id, $done->approved_by);
        $this->assertNotNull($done->approved_at);
        $this->assertNotNull($done->issued_at);
        $this->assertNull($done->override_reason);

        $this->assertSame('issued', AuditLog::forRecord($done)->where('event', 'issued')->firstOrFail()->event);
    }

    public function test_unpaid_dues_hold_the_certificate_and_clear_once_paid(): void
    {
        $this->fee(1000);
        $s = $this->kid();

        $t = $this->transfers()->request($s, $this->admin);
        $this->assertSame('requested', $t->status);
        $this->assertSame(1000.0, (float) $t->dues_total);
        $this->assertSame('Admission fee', $t->dues_detail[0]['label']);

        // nobody is blocked from asking, but approving is stopped
        try {
            $this->transfers()->approve($t, $this->admin);
            $this->fail('dues must block the certificate');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1,000.00', collect($e->errors())->flatten()->implode(' '));
        }

        $this->assertSame('active', $s->fresh()->status);
        $this->assertSame(0, Certificate::count());

        $this->pay()->record($s, 1000, 'cash', null, null, $this->admin);
        $this->assertSame('cleared', $this->transfers()->recheck($t, $this->admin)->status);

        $this->assertSame('issued', $this->transfers()->approve($t, $this->admin)->status);
    }

    public function test_the_dues_check_is_repeated_at_the_moment_of_approval(): void
    {
        $this->fee(1000);
        $s = $this->kid();
        $this->pay()->record($s, 1000, 'cash', null, null, $this->admin);
        $t = $this->transfers()->request($s, $this->admin);                                   // cleared

        // the payment is voided after the request, before the approval
        $this->pay()->void(AdmissionPayment::firstOrFail(), $this->admin, 'Bounced');

        $this->expectException(ValidationException::class);
        $this->transfers()->approve($t, $this->admin);
    }

    public function test_overriding_the_dues_needs_the_permission_and_a_reason_and_is_logged(): void
    {
        $this->fee(1000);
        $s = $this->kid();
        $t = $this->transfers()->request($s, $this->admin);

        // may approve, may not override
        $approver = $this->user(['students.approve_transfer'], 'approver');
        try {
            $this->transfers()->approve($t, $approver, 'Please');
            $this->fail('no override permission');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dues', $e->errors());
        }

        // may override, but gave no reason
        $both = $this->user(['students.approve_transfer', 'students.override_dues'], 'both');
        try {
            $this->transfers()->approve($t, $both, '  ');
            $this->fail('no reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('override_reason', $e->errors());
        }
        $this->assertSame('active', $s->fresh()->status);

        $done = $this->transfers()->approve($t, $both, 'Guardian signed a promise to pay by next month');

        $this->assertSame('issued', $done->status);
        $this->assertSame('Guardian signed a promise to pay by next month', $done->override_reason);
        $this->assertSame($both->id, $done->override_by);
        $this->assertSame(1000.0, (float) $done->dues_total);                                  // the debt that was overridden is on record

        $log = AuditLog::forRecord($done)->where('event', 'issued')->firstOrFail();
        $this->assertTrue($log->changes['dues_overridden']);
    }

    public function test_the_permissions_for_each_step(): void
    {
        $s = $this->kid();
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        $this->expectException(AuthorizationException::class);
        $this->transfers()->request($s, $teacher);
    }

    public function test_only_an_approver_approves_and_only_active_students_with_no_open_transfer_can_be_requested(): void
    {
        $s = $this->kid();
        $requester = $this->user(['students.request_transfer'], 'requester');
        $t = $this->transfers()->request($s, $requester);

        try {
            $this->transfers()->approve($t, $requester);
            $this->fail('a requester cannot approve');
        } catch (AuthorizationException) {
        }

        try {
            $this->transfers()->request($s, $requester);
            $this->fail('a second open transfer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already in progress', $e->errors()['student'][0]);
        }

        $left = $this->kid();
        $left->update(['status' => 'dropped']);
        $this->expectException(ValidationException::class);
        $this->transfers()->request($left, $requester);
    }

    public function test_rejecting_and_cancelling_need_a_remark_and_close_the_transfer(): void
    {
        $this->fee(1000);
        $a = $this->kid();
        $b = $this->kid();
        $requester = $this->user(['students.request_transfer'], 'requester');
        $ta = $this->transfers()->request($a, $requester);
        $tb = $this->transfers()->request($b, $this->admin);

        try {
            $this->transfers()->reject($ta, $this->admin, ' ');
            $this->fail('a remark is required');
        } catch (ValidationException) {
        }

        $this->assertSame('rejected', $this->transfers()->reject($ta, $this->admin, 'Dues not cleared')->status);
        $this->assertSame('cancelled', $this->transfers()->cancel($tb, $this->admin, 'Family changed its mind')->status);

        try {
            $this->transfers()->approve($ta, $this->admin);
            $this->fail('a closed transfer cannot be approved');
        } catch (ValidationException) {
        }

        // a stranger cannot cancel someone else's request
        $c = $this->kid();
        $tc = $this->transfers()->request($c, $requester);
        $this->expectException(AuthorizationException::class);
        $this->transfers()->cancel($tc, $this->user(['students.request_transfer'], 'other_requester'), 'no');
    }

    public function test_a_transferred_student_cannot_be_made_active_except_by_reinstating_with_a_reason(): void
    {
        $s = $this->kid();
        $this->transfers()->approve($this->transfers()->request($s, $this->admin), $this->admin);
        $s->refresh();

        // no other route works: not the model, not a status change
        try {
            $s->update(['status' => 'active']);
            $this->fail('the model must refuse');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('reinstated', $e->getMessage());
        }
        $s->refresh();                                                                 // drop the refused, unsaved value
        $this->assertSame('transferred', $s->status);

        // ordinary edits of the same student are still fine
        $s->update(['roll_number' => '77']);
        $this->assertSame('77', $s->fresh()->roll_number);

        // without the permission
        try {
            $this->transfers()->reinstate($s, $this->user(['students.approve_transfer'], 'approver'), 'Because');
            $this->fail('permission');
        } catch (AuthorizationException) {
        }

        // without a reason
        try {
            $this->transfers()->reinstate($s, $this->admin, '  ');
            $this->fail('reason');
        } catch (ValidationException) {
        }

        $back = $this->transfers()->reinstate($s, $this->admin, 'The transfer fell through');
        $this->assertSame('active', $back->status);

        $t = StudentTransfer::firstOrFail();
        $this->assertSame('The transfer fell through', $t->reinstate_reason);
        $this->assertSame($this->admin->id, $t->reinstated_by);
        $this->assertNotNull($t->reinstated_at);
        $this->assertSame('reinstated', AuditLog::forRecord($back)->where('event', 'reinstated')->firstOrFail()->event);

        // the lock is back afterwards
        $back->update(['status' => 'transferred']);
        $this->expectException(\DomainException::class);
        $back->fresh()->update(['status' => 'active']);
    }

    // ------------------------------------------------------------------
    // certificates
    // ------------------------------------------------------------------

    public function test_serial_numbers_count_up_per_type_and_year_and_cannot_be_changed(): void
    {
        $s = $this->kid();
        $svc = app(CertificateService::class);
        $y = now()->year;

        $a = $svc->issue('testimonial', $s->profile, $s, $this->admin, 'For a scholarship');
        $b = $svc->issue('testimonial', $s->profile, $s, $this->admin);
        $c = $svc->issue('character', $s->profile, $s, $this->admin);

        $this->assertSame(["TM-{$y}-0001", "TM-{$y}-0002", "CC-{$y}-0001"], [$a->serial, $b->serial, $c->serial]);
        $this->assertSame('For a scholarship', $a->remarks);
        $this->assertSame($this->admin->id, $a->issued_by);

        try {
            $a->update(['serial' => 'TM-1999-0001']);
            $this->fail('serials are fixed');
        } catch (\LogicException) {
        }
        $this->assertSame("TM-{$y}-0001", $a->fresh()->serial);

        // a certificate that was deleted does not give its number away
        $b->delete();
        $d = $svc->issue('testimonial', $s->profile, $s, $this->admin);
        $this->assertSame("TM-{$y}-0003", $d->serial);
        $this->assertNotNull(Certificate::withTrashed()->find($b->id));
    }

    public function test_a_transfer_certificate_is_only_issued_through_the_workflow_and_others_need_the_permission(): void
    {
        $s = $this->kid();
        $svc = app(CertificateService::class);

        try {
            $svc->issue('transfer', $s->profile, $s, $this->admin);
            $this->fail('a TC cannot be issued directly');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Transfers workflow', $e->errors()['type'][0]);
        }

        try {
            $svc->issue('testimonial', $s->profile, $s, $this->makeUser(User::ROLE_TEACHER));
            $this->fail('permission');
        } catch (AuthorizationException) {
        }

        try {
            $svc->issue('diploma', $s->profile, $s, $this->admin);
            $this->fail('unknown type');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Certificate::count());
    }

    public function test_every_print_is_counted_and_a_voided_certificate_stays_in_the_register(): void
    {
        $s = $this->kid();
        $svc = app(CertificateService::class);
        $c = $svc->issue('character', $s->profile, $s, $this->admin);

        $svc->printed($c, $this->admin);
        $svc->printed($c, $this->admin);

        $this->assertSame(2, $c->fresh()->print_count);
        $this->assertNotNull($c->fresh()->last_printed_at);

        $svc->void($c, $this->admin, 'Name misspelt');
        $this->assertNotNull($c->fresh()->voided_at);
        $this->assertSame('Name misspelt', $c->fresh()->void_reason);
        $this->assertSame($c->serial, $c->fresh()->serial);
    }

    // ------------------------------------------------------------------
    // online applications
    // ------------------------------------------------------------------

    private function application(array $over = []): OnlineApplication
    {
        return app(OnlineAdmissionService::class)->submit($over + [
            'academic_year' => $this->year, 'class_name' => 'Class 6', 'name_en' => 'Online Applicant', 'dob' => '2014-03-03', 'gender' => 'Female',
            'guardian_mobile' => '01755555555', 'father_name' => 'Applicant Senior',
        ], '203.0.113.7');
    }

    public function test_an_application_gets_a_number_and_a_repeat_submission_is_kept_once(): void
    {
        $a = $this->application();
        $again = $this->application();

        $this->assertSame('OA-' . $this->year . '-00001', $a->application_no);
        $this->assertSame('pending', $a->status);
        $this->assertSame($a->id, $again->id);
        $this->assertSame(1, OnlineApplication::count());
        $this->assertSame('OA-' . $this->year . '-00002', $this->application(['name_en' => 'Second Applicant', 'guardian_mobile' => '01766666666'])->application_no);
    }

    public function test_approving_creates_the_student_and_links_it(): void
    {
        $a = $this->application(['brn' => '20141234567890123']);
        $svc = app(OnlineAdmissionService::class);

        $student = $svc->approve($a, $this->admin, $this->class->id, '12');

        $a->refresh();
        $this->assertSame('approved', $a->status);
        $this->assertSame($this->admin->id, $a->decided_by);
        $this->assertSame($student->profile_id, $a->profile_id);
        $this->assertSame($student->id, $a->enrollment_id);
        $this->assertSame('Online Applicant', $student->student_name);
        $this->assertSame('20141234567890123', $student->profile->brn);
        $this->assertSame('12', $student->roll_number);
        $this->assertSame('approved', AuditLog::forRecord($a)->where('event', 'approved')->firstOrFail()->event);

        $this->expectException(ValidationException::class);                                      // decided once
        $svc->approve($a, $this->admin, $this->class->id);
    }

    public function test_the_duplicate_rules_apply_when_approving_and_a_blocked_application_stays_pending(): void
    {
        app(StudentRegistry::class)->admit(['name_en' => 'Already Here', 'dob' => '2010-01-01', 'brn' => '20141234567890123', 'guardian_mobile' => '01999999999', 'class_id' => $this->class->id], $this->admin);
        $a = $this->application(['brn' => '20141234567890123']);

        try {
            app(OnlineAdmissionService::class)->approve($a, $this->admin, $this->class->id);
            $this->fail('the BRN already exists');
        } catch (DuplicateStudentException $e) {
            $this->assertSame('brn', $e->kind);
        }

        $this->assertSame('pending', $a->fresh()->status);
        $this->assertSame(1, Student::count());
    }

    public function test_rejecting_needs_a_remark_and_only_the_permitted_can_decide(): void
    {
        $a = $this->application();
        $svc = app(OnlineAdmissionService::class);

        try {
            $svc->reject($a, $this->admin, ' ');
            $this->fail('remark');
        } catch (ValidationException) {
        }

        try {
            $svc->reject($a, $this->makeUser(User::ROLE_TEACHER), 'no');
            $this->fail('permission');
        } catch (AuthorizationException) {
        }

        $done = $svc->reject($a, $this->admin, 'No seat left in Class 6');
        $this->assertSame('rejected', $done->status);
        $this->assertSame('No seat left in Class 6', $done->decision_remark);
        $this->assertSame(0, Student::count());

        $this->expectException(ValidationException::class);
        $svc->approve($a, $this->admin, $this->class->id);
    }
}
