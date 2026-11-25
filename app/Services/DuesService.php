<?php

namespace App\Services;

use App\Models\AdmissionFee;
use App\Models\AdmissionPayment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a student still owes, itemised so that staff can see why a transfer is held.
 *
 *   Admission   the admission fee of the class for the session, less the receipts collected (voided ones do not count)
 *   Fees        the school's own fee structures of the class that are charged once a year or once only
 *               (config student.fee_cycles_counted_as_dues), less discounts, less everything paid through the
 *               fee module that year. Monthly structures are left out: the fee module keeps no single "owed so far"
 *               figure for them, so they cannot be judged here.
 */
class DuesService
{
    /** @return array{total: float, items: array<int,array{label:string, expected:float, paid:float, due:float}>} */
    public function for(Student $enrollment): array
    {
        $items = [];

        $items[] = $this->admission($enrollment);

        if ($fees = $this->fees($enrollment)) {
            $items[] = $fees;
        }

        $items = array_values(array_filter($items, fn ($i) => $i['expected'] > 0 || $i['due'] > 0));

        return ['total' => round(array_sum(array_column($items, 'due')), 2), 'items' => $items];
    }

    public function admissionExpected(Student $enrollment): float
    {
        $class = $enrollment->schoolClass;

        return $class ? (float) AdmissionFee::where('academic_year', (string) $enrollment->academic_year)->where('class_name', $class->class_name)->value('amount') : 0.0;
    }

    public function admissionPaid(Student $enrollment): float
    {
        return (float) AdmissionPayment::where('student_id', $enrollment->id)->valid()->sum('amount');
    }

    private function admission(Student $e): array
    {
        $expected = $this->admissionExpected($e);
        $paid = $this->admissionPaid($e);

        return ['label' => 'Admission fee', 'expected' => $expected, 'paid' => $paid, 'due' => round(max(0, $expected - $paid), 2)];
    }

    private function fees(Student $e): ?array
    {
        if (! Schema::hasTable('fee_structures')) {
            return null;
        }

        $cycles = array_map('strtolower', (array) config('student.fee_cycles_counted_as_dues', []));

        $structures = DB::table('fee_structures')
            ->where('class_id', $e->class_id)->where('academic_year', (string) $e->academic_year)->where('is_active', true)
            ->get(['id', 'amount', 'cycle'])
            ->filter(fn ($s) => in_array(strtolower((string) $s->cycle), $cycles, true));

        if ($structures->isEmpty()) {
            return null;
        }

        $policies = DB::table('student_fee_policies')->where('student_id', $e->id)->where('academic_year', (string) $e->academic_year)
            ->whereIn('fee_structure_id', $structures->pluck('id'))->get()->keyBy('fee_structure_id');

        $expected = 0.0;
        foreach ($structures as $s) {
            $amount = (float) $s->amount;
            $p = $policies[$s->id] ?? null;

            if ($p) {
                $amount -= str_contains(strtolower((string) $p->discount_type), 'percent') ? $amount * ((float) $p->discount_value / 100) : (float) $p->discount_value;
            }

            $expected += max(0, $amount);
        }

        $paid = (float) DB::table('fee_payments')->where('student_id', $e->id)->where('academic_year', (string) $e->academic_year)->sum('amount');

        return ['label' => 'School fees', 'expected' => round($expected, 2), 'paid' => round($paid, 2), 'due' => round(max(0, $expected - $paid), 2)];
    }
}
