<?php

namespace Tests\Feature;

use App\Models\AdmissionFee;
use App\Models\AdmissionPayment;
use App\Models\Certificate;
use App\Models\OnlineApplication;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentTransfer;
use App\Models\User;
use App\Services\StudentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

/** The screens and endpoints for payments, transfers, certificates and online admission. */
class AdmissionHttpTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $class;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class = $this->makeClass('Class 6', 'A');
    }

    private function kid(): Student
    {
        $this->n++;

        return app(StudentRegistry::class)->admit([
            'name_en' => 'Karim Hossain ' . chr(64 + $this->n), 'dob' => now()->subYears(11)->addDays($this->n)->toDateString(),
            'guardian_mobile' => '0172' . str_pad((string) $this->n, 7, '0', STR_PAD_LEFT), 'class_id' => $this->class->id, 'roll_number' => (string) $this->n,
        ], $this->admin);
    }

    private function user(array $abilities): User
    {
        $slug = 'r_' . substr(md5(json_encode($abilities)), 0, 8);
        Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'abilities' => $abilities, 'is_system' => false]);

        return $this->makeUser($slug);
    }

    private function applicationData(array $over = []): array
    {
        return $over + [
            'class_name' => $this->class->class_name, 'name_en' => 'Nusrat Jahan', 'dob' => now()->subYears(10)->toDateString(), 'gender' => 'Female',
            'father_name' => 'Abdul Jalil', 'guardian_mobile' => '01711000111', 'website' => '',
        ];
    }

    public function test_pages_need_their_permission(): void
    {
        $nobody = $this->user([]);

        foreach (['sm.admission-payments.index', 'sm.certificates.index', 'sm.online-admissions.index', 'sm.transfers.index'] as $name) {
            $this->actingAs($nobody)->get(route($name))->assertForbidden();
        }
    }

    public function test_admin_sees_every_page(): void
    {
        foreach ([['sm.admission-payments.index', 'StudentModule/AdmissionPayments'], ['sm.certificates.index', 'StudentModule/Certificates'],
                  ['sm.online-admissions.index', 'StudentModule/OnlineAdmissions'], ['sm.transfers.index', 'StudentModule/Transfers']] as [$name, $page]) {
            $this->actingAs($this->admin)->get(route($name))->assertOk()->assertInertia(fn (Assert $p) => $p->component($page));
        }
    }

    public function test_fees_payment_receipt_and_void(): void
    {
        $student = $this->kid();
        $year = (string) $this->class->academic_year;

        $this->actingAs($this->admin)->post(route('sm.admission-payments.fees'), ['session' => $year, 'fees' => ['Class 6' => 1500]])->assertRedirect();
        $this->assertSame('1500.00', AdmissionFee::where('class_name', 'Class 6')->first()->amount);

        $this->actingAs($this->admin)->post(route('sm.admission-payments.store'), ['enrollment_id' => $student->id, 'amount' => 500, 'method' => 'cash'])->assertRedirect();
        $payment = AdmissionPayment::firstOrFail();

        $this->actingAs($this->admin)->get(route('sm.admission-payments.receipt', $payment))->assertOk()->assertSee($payment->receipt_no);

        $this->actingAs($this->admin)->get(route('sm.admission-payments.index', ['session' => $year]))
            ->assertInertia(fn (Assert $p) => $p->where('summary.collected', fn ($v) => (float) $v === 500.0)->where('summary.due', fn ($v) => (float) $v === 1000.0));

        $this->actingAs($this->admin)->post(route('sm.admission-payments.void', $payment), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('sm.admission-payments.void', $payment), ['reason' => 'wrong student'])->assertRedirect();
        $this->assertNotNull($payment->fresh()->voided_at);

        $this->actingAs($this->admin)->post(route('sm.admission-payments.store'), ['enrollment_id' => $student->id, 'amount' => -5, 'method' => 'cash'])->assertSessionHasErrors('amount');
    }

    public function test_dues_export_downloads(): void
    {
        $this->kid();
        AdmissionFee::create(['academic_year' => (string) $this->class->academic_year, 'class_name' => 'Class 6', 'amount' => 900]);

        $r = $this->actingAs($this->admin)->get(route('sm.admission-payments.export', ['session' => (string) $this->class->academic_year, 'format' => 'csv']));
        $r->assertOk();
        $this->assertStringContainsString('Karim Hossain', (string) file_get_contents($r->baseResponse->getFile()->getPathname()));
        $this->actingAs($this->admin)->get(route('sm.admission-payments.export', ['session' => 'abc']))->assertStatus(422);
    }

    public function test_transfer_flow_over_http_and_print(): void
    {
        $student = $this->kid();

        $this->actingAs($this->admin)->post(route('sm.transfers.store'), ['profile_id' => $student->profile_id, 'reason' => 'Moving', 'destination' => 'Dhaka School'])->assertRedirect(route('sm.transfers.index'));
        $transfer = StudentTransfer::firstOrFail();

        $this->actingAs($this->admin)->post(route('sm.transfers.approve', $transfer))->assertRedirect();
        $transfer->refresh();
        $this->assertSame('issued', $transfer->status);
        $this->assertSame('transferred', $student->fresh()->status);

        $this->actingAs($this->admin)->get(route('sm.certificates.print', $transfer->certificate_id))->assertOk()->assertSee($transfer->certificate->serial)->assertSee('Transfer');
        $this->assertSame(1, $transfer->certificate->fresh()->print_count);

        $this->actingAs($this->admin)->post(route('sm.students.reinstate', $student->profile_id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('sm.students.reinstate', $student->profile_id), ['reason' => 'Came back'])->assertRedirect();
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_transfer_is_blocked_by_dues_and_by_missing_permission(): void
    {
        $student = $this->kid();
        AdmissionFee::create(['academic_year' => (string) $this->class->academic_year, 'class_name' => 'Class 6', 'amount' => 800]);

        $this->actingAs($this->admin)->post(route('sm.transfers.store'), ['profile_id' => $student->profile_id]);
        $transfer = StudentTransfer::firstOrFail();

        $this->actingAs($this->admin)->post(route('sm.transfers.recheck', $transfer));
        $this->assertGreaterThan(0, (float) $transfer->fresh()->dues_total);

        $clerk = $this->user(['students.request_transfer']);
        $this->actingAs($clerk)->post(route('sm.transfers.approve', $transfer))->assertForbidden();
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_certificates_register_issue_void_and_no_tc_by_hand(): void
    {
        $student = $this->kid();

        $this->actingAs($this->admin)->post(route('sm.certificates.store'), ['profile_id' => $student->profile_id, 'type' => 'testimonial'])->assertRedirect();
        $cert = Certificate::firstOrFail();

        // a Transfer Certificate can only come from a transfer
        $this->actingAs($this->admin)->post(route('sm.certificates.store'), ['profile_id' => $student->profile_id, 'type' => 'transfer'])->assertSessionHasErrors('type');

        $this->actingAs($this->admin)->get(route('sm.certificates.index', ['q' => $cert->serial]))->assertInertia(fn (Assert $p) => $p->has('certificates.data', 1));

        $this->actingAs($this->admin)->post(route('sm.certificates.void', $cert), ['reason' => 'typo'])->assertRedirect();
        $this->actingAs($this->admin)->get(route('sm.certificates.print', $cert))->assertStatus(410);

        $viewer = $this->user(['students.view_certificates']);
        $this->actingAs($viewer)->post(route('sm.certificates.store'), ['profile_id' => $student->profile_id, 'type' => 'character'])->assertForbidden();
    }

    public function test_public_application_is_stored_and_approved(): void
    {
        $this->post(route('apply.store'), $this->applicationData())->assertRedirect(route('apply.show'));
        $app = OnlineApplication::firstOrFail();
        $this->assertSame('pending', $app->status);

        $this->get(route('apply.show'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('PublicApply')->where('done.no', $app->application_no));

        $this->actingAs($this->admin)->post(route('sm.online-admissions.approve', $app), ['class_id' => $this->class->id])->assertRedirect();
        $this->assertSame('approved', $app->fresh()->status);
        $this->assertSame(1, Student::where('student_name', 'Nusrat Jahan')->count());
    }

    public function test_public_application_rejects_bad_input_and_bots(): void
    {
        $this->post(route('apply.store'), $this->applicationData(['guardian_mobile' => '123']))->assertSessionHasErrors('guardian_mobile');
        $this->post(route('apply.store'), $this->applicationData(['brn' => '123']))->assertSessionHasErrors('brn');
        $this->post(route('apply.store'), $this->applicationData(['class_name' => 'Class 99']))->assertSessionHasErrors('class_name');
        $this->post(route('apply.store'), $this->applicationData(['dob' => now()->addDay()->toDateString()]))->assertSessionHasErrors('dob');

        // the honeypot: looks accepted, stores nothing
        $this->post(route('apply.store'), $this->applicationData(['website' => 'http://spam.example']))->assertRedirect();
        $this->assertSame(0, OnlineApplication::count());
    }

    public function test_public_application_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('apply.store'), $this->applicationData(['name_en' => "Child $i"]));
        }

        $this->post(route('apply.store'), $this->applicationData(['name_en' => 'Child 6']))->assertStatus(429);
    }

    public function test_application_can_be_rejected_with_a_remark(): void
    {
        $this->post(route('apply.store'), $this->applicationData());
        $app = OnlineApplication::firstOrFail();

        $this->actingAs($this->admin)->post(route('sm.online-admissions.reject', $app), ['remark' => ''])->assertSessionHasErrors('remark');
        $this->actingAs($this->admin)->post(route('sm.online-admissions.reject', $app), ['remark' => 'No seat'])->assertRedirect();
        $this->assertSame('rejected', $app->fresh()->status);
    }

    public function test_the_old_status_screen_cannot_transfer_a_student_out(): void
    {
        $student = $this->kid();

        $this->actingAs($this->admin)->post(route('students.status', $student), ['status' => 'transferred'])->assertSessionHas('error');
        $this->assertSame('active', $student->fresh()->status);

        // other statuses still work there
        $this->actingAs($this->admin)->post(route('students.status', $student), ['status' => 'suspended', 'reason' => 'x'])->assertRedirect();
        $this->assertSame('suspended', $student->fresh()->status);
    }

    public function test_the_old_status_screen_cannot_reactivate_a_transferred_student(): void
    {
        $student = $this->kid();
        $this->actingAs($this->admin)->post(route('sm.transfers.store'), ['profile_id' => $student->profile_id]);
        $this->actingAs($this->admin)->post(route('sm.transfers.approve', StudentTransfer::firstOrFail()));
        $this->assertSame('transferred', $student->fresh()->status);

        $this->actingAs($this->admin)->post(route('students.status', $student), ['status' => 'active'])->assertSessionHas('error');
        $this->assertSame('transferred', $student->fresh()->status);
    }

    public function test_manual_promotion_can_request_a_transfer(): void
    {
        $student = $this->kid();
        $year = (string) $this->class->academic_year;

        $run = app(\App\Services\StudentPromotion::class)->applyManual($year, (string) ((int) $year + 1), [['enrollment_id' => $student->id, 'action' => 'transfer']], $this->admin);

        $this->assertSame(1, $run->transferred);
        $this->assertSame(1, StudentTransfer::where('enrollment_id', $student->id)->open()->count());
        $this->assertSame('active', $student->fresh()->status);   // not transferred until it is approved
        $this->assertSame(0, Student::where('profile_id', $student->profile_id)->where('academic_year', (int) $year + 1)->count());
    }

    public function test_manual_promotion_transfer_needs_the_permission(): void
    {
        $student = $this->kid();
        $year = (string) $this->class->academic_year;
        $promoter = $this->user(['students.promote_students']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\StudentPromotion::class)->applyManual($year, (string) ((int) $year + 1), [['enrollment_id' => $student->id, 'action' => 'transfer']], $promoter);
    }
}
