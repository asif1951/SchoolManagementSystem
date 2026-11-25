<?php

namespace App\Services;

use App\Models\AdmissionFee;
use App\Models\AdmissionPayment;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Support\Serial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admission fees and the money collected for them: recording a payment (with a receipt number that is never
 * reused), voiding a wrong one, and the summary — expected, collected and due — by session, class, date and method.
 */
class AdmissionPayments
{
    public function __construct(private DuesService $dues)
    {
    }

    /** @throws ValidationException */
    public function record(Student $enrollment, float $amount, string $method, ?string $reference, ?string $note, User $by, ?Carbon $paidAt = null): AdmissionPayment
    {
        if (! array_key_exists($method, AdmissionPayment::METHODS)) {
            throw ValidationException::withMessages(['method' => 'Choose cash, bKash, Nagad or bank.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }

        if ($enrollment->status === 'transferred') {
            throw ValidationException::withMessages(['student' => 'This student has been transferred out.']);
        }

        return DB::transaction(function () use ($enrollment, $amount, $method, $reference, $note, $by, $paidAt) {
            // locked, so two cashiers cannot both collect the last instalment
            $locked = Student::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            $expected = $this->dues->admissionExpected($locked);
            $due = $expected - $this->dues->admissionPaid($locked);

            if ($expected > 0 && $amount - $due > 0.005) {
                throw ValidationException::withMessages(['amount' => 'This is more than is due (' . number_format($due, 2) . ').']);
            }

            $year = (int) $locked->academic_year;

            $payment = AdmissionPayment::create([
                'receipt_no'    => Serial::format('AD', $year % 100, Serial::next("receipt:{$year}"), 6),
                'student_id'    => $locked->id,
                'profile_id'    => $locked->profile_id,
                'academic_year' => (string) $locked->academic_year,
                'amount'        => round($amount, 2),
                'method'        => $method,
                'reference'     => $reference ?: null,
                'note'          => $note ?: null,
                'collected_by'  => $by->id,
                'paid_at'       => $paidAt ?? now(),
            ]);

            AuditLog::record($payment, 'created', ['receipt' => $payment->receipt_no, 'student' => $locked->student_id, 'amount' => (string) $payment->amount, 'method' => $method], $by->id);

            return $payment;
        });
    }

    /** A receipt is never deleted: a wrong one is voided, keeps its number and stops counting. */
    public function void(AdmissionPayment $payment, User $by, string $reason): AdmissionPayment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for voiding this receipt.']);
        }

        if ($payment->voided_at) {
            throw ValidationException::withMessages(['reason' => 'This receipt is already void.']);
        }

        $payment->update(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason]);
        AuditLog::record($payment, 'voided', ['receipt' => $payment->receipt_no, 'reason' => $reason], $by->id);

        return $payment;
    }

    /** Set what admission costs for each class of a session ("" or 0 removes it). */
    public function setFees(string $session, array $amounts): void
    {
        DB::transaction(function () use ($session, $amounts) {
            foreach ($amounts as $className => $amount) {
                $amount = (float) $amount;

                if ($amount > 0) {
                    AdmissionFee::updateOrCreate(['academic_year' => $session, 'class_name' => $className], ['amount' => $amount]);
                } else {
                    AdmissionFee::where('academic_year', $session)->where('class_name', $className)->delete();
                }
            }
        });
    }

    /**
     * Expected, collected and due for a session.
     *
     *   expected   the admission fee of every student's class (everyone live in the session, except those transferred out)
     *   collected  receipts dated within [from, to] (all of them when no dates are given), optionally of one method
     *   due        expected less everything received up to the end date (or today)
     *
     * @return array{expected:float, collected:float, due:float, by_method:array, by_class:array, with_dues:array}
     */
    public function summary(string $session, ?string $className = null, ?string $from = null, ?string $to = null, ?string $method = null): array
    {
        $until = $to ? Carbon::parse($to)->endOfDay() : now();

        // one pass in the database: fee and paid-so-far per student, then totals by class — never a PHP loop over students
        $base = $this->duesBase($session, $className, $until);

        $perClass = (clone $base)->selectRaw('classes.class_name as cls, COUNT(*) as n, SUM(COALESCE(admission_fees.amount, 0)) as expected,
                SUM(GREATEST(COALESCE(admission_fees.amount, 0) - COALESCE(p.paid, 0), 0)) as due, SUM(COALESCE(admission_fees.amount, 0) - COALESCE(p.paid, 0) > 0.004) as owing')
            ->groupBy('classes.class_name')->get();

        $withDues = (clone $base)->whereRaw('COALESCE(admission_fees.amount, 0) - COALESCE(p.paid, 0) > 0.004')
            ->select('students.id', 'students.roll_number', 'students.student_name', 'students.student_id as code', 'student_profiles.guardian_mobile', 'classes.class_name as cls_name',
                'classes.section as cls_section', DB::raw('COALESCE(admission_fees.amount, 0) as fee'), DB::raw('COALESCE(p.paid, 0) as paid'))
            ->orderByRaw('COALESCE(admission_fees.amount, 0) - COALESCE(p.paid, 0) DESC')->orderBy('students.id')->limit(self::SCREEN_ROWS)->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->student_name, 'code' => $s->code, 'class' => trim($s->cls_name . ' ' . $s->cls_section), 'roll' => $s->roll_number,
                              'mobile' => $s->guardian_mobile, 'fee' => (float) $s->fee, 'paid' => (float) $s->paid, 'due' => round(max(0, (float) $s->fee - (float) $s->paid), 2)])->all();

        // the money that came in inside the range, by method and by class (receipts of the same students)
        $collected = fn () => DB::table('admission_payments as ap')->join('students', 'students.id', '=', 'ap.student_id')->join('classes', 'classes.id', '=', 'students.class_id')
            ->whereNull('ap.voided_at')->where('ap.academic_year', $session)->where('students.academic_year', (int) $session)->whereNull('students.deleted_at')->where('students.status', '!=', 'transferred')
            ->when($className, fn ($q) => $q->where('classes.class_name', $className))
            ->when($from, fn ($q) => $q->where('ap.paid_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($to, fn ($q) => $q->where('ap.paid_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($method, fn ($q) => $q->where('ap.method', $method));

        $byMethod = $collected()->selectRaw('ap.method as method, COUNT(*) as n, SUM(ap.amount) as total')->groupBy('ap.method')->get()
            ->mapWithKeys(fn ($r) => [$r->method => ['count' => (int) $r->n, 'total' => (float) $r->total]])->all();

        $collectedByClass = $collected()->selectRaw('classes.class_name as cls, SUM(ap.amount) as total')->groupBy('classes.class_name')->pluck('total', 'cls');

        $byClass = $perClass->map(fn ($c) => ['class' => $c->cls, 'students' => (int) $c->n, 'expected' => round((float) $c->expected, 2),
                                             'collected' => round((float) ($collectedByClass[$c->cls] ?? 0), 2), 'due' => round((float) $c->due, 2)])
            ->sortBy('class', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [
            'expected' => round((float) $perClass->sum('expected'), 2), 'collected' => round(array_sum(array_column($byMethod, 'total')), 2), 'due' => round((float) $perClass->sum('due'), 2),
            'by_method' => $byMethod, 'by_class' => $byClass,
            'with_dues' => $withDues, 'with_dues_count' => (int) $perClass->sum('owing'), 'with_dues_shown' => count($withDues),
        ];
    }

    /** the most students with dues shown on the screen (the full list is the export) */
    public const SCREEN_ROWS = 200;

    /**
     * The students of a session with what they owe, every one, in a stable order, a page at a time: the source of
     * the dues export. Memory stays flat however many students there are.
     *
     * @return \Generator<int,array<int,mixed>>
     */
    public function duesRows(string $session, ?string $className = null, ?string $to = null, int $page = 5000): \Generator
    {
        $until = $to ? Carbon::parse($to)->endOfDay() : now();
        $last = 0;

        yield ['Student code', 'Name', 'Class', 'Roll', 'Guardian mobile', 'Fee', 'Paid', 'Due'];

        do {
            $rows = $this->duesBase($session, $className, $until)->where('students.id', '>', $last)
                ->whereRaw('COALESCE(admission_fees.amount, 0) - COALESCE(p.paid, 0) > 0.004')
                ->select('students.id', 'students.roll_number', 'students.student_name', 'students.student_id as code', 'student_profiles.guardian_mobile', 'classes.class_name as cls_name',
                    'classes.section as cls_section', DB::raw('COALESCE(admission_fees.amount, 0) as fee'), DB::raw('COALESCE(p.paid, 0) as paid'))
                ->orderBy('students.id')->limit($page)->get();

            foreach ($rows as $s) {
                yield [$s->code, $s->student_name, trim($s->cls_name . ' ' . $s->cls_section), $s->roll_number, $s->guardian_mobile, (float) $s->fee, (float) $s->paid, round(max(0, (float) $s->fee - (float) $s->paid), 2)];
            }

            $last = $rows->last()->id ?? $last;
        } while ($rows->count() === $page);
    }

    /** students of the session (not transferred out) with their class fee and what they have paid up to $until */
    private function duesBase(string $session, ?string $className, Carbon $until)
    {
        $paid = DB::table('admission_payments')->whereNull('voided_at')->where('academic_year', $session)->where('paid_at', '<=', $until)
            ->selectRaw('student_id, SUM(amount) as paid')->groupBy('student_id');

        return Student::query()
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->join('student_profiles', 'student_profiles.id', '=', 'students.profile_id')
            ->leftJoin('admission_fees', fn ($j) => $j->on('admission_fees.academic_year', '=', 'classes.academic_year')->on('admission_fees.class_name', '=', 'classes.class_name'))
            ->leftJoinSub($paid, 'p', 'p.student_id', '=', 'students.id')
            ->where('students.academic_year', (int) $session)
            ->where('students.status', '!=', 'transferred')
            ->when($className, fn ($q) => $q->where('classes.class_name', $className));
    }
}
