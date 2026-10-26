<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CollectFeesController extends Controller
{
    // ─────────────────────────────────────────────
    // INDEX — Class list
    // ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $selectedYear = (string) ($request->input('academic_year') ?? now()->year);

        [$classes, $availableYears] = $this->buildClassData($selectedYear);

        return Inertia::render('fees', [
            'classes'        => $classes,
            'classInfo'      => null,
            'feeTypes'       => [],
            'students'       => [],
            'availableYears' => $availableYears,
            'filters'        => [
                'academic_year' => $selectedYear,
                'search'        => '',
            ],
            'flash'          => session()->only(['success', 'error']),
        ]);
    }

    // ─────────────────────────────────────────────
    // SHOW — Student list for a class + payments + logs
    // ─────────────────────────────────────────────
    public function show(Request $request, int $classId)
    {
        $cls = SchoolClass::findOrFail($classId);

        [$classes, $availableYears] = $this->buildClassData((string) $cls->academic_year);

        $feeTypes = $this->buildFeeTypes($cls);

        // ── Students ──
        $search = trim((string) $request->input('search', ''));

        $studentsQuery = DB::table('students')
            ->where('class', $cls->class_name)
            ->where('academic_year', (string) $cls->academic_year)
            ->where('status', 'active');

        if ($cls->section) {
            $studentsQuery->where('section', $cls->section);
        } else {
            $studentsQuery->where(function ($w) {
                $w->whereNull('section')->orWhere('section', '');
            });
        }

        if ($search !== '') {
            $studentsQuery->where(function ($q) use ($search) {
                $q->where('student_name', 'like', "%{$search}%")
                  ->orWhere('student_id', 'like', "%{$search}%")
                  ->orWhere('roll_number', 'like', "%{$search}%");
            });
        }

        $students = $studentsQuery
            ->orderBy('student_name')
            ->get()
            ->map(function ($student) {
                return [
                    'id'          => $student->id,
                    'name'        => $student->student_name,
                    'student_id'  => $student->student_id,
                    'class'       => $student->class ?? 'N/A',
                    'section'     => $student->section ?: 'N/A',
                    'roll_number' => $student->roll_number,
                    'photo'       => $student->student_photo ?? null,
                ];
            })
            ->toArray();

        // ── Existing policies ──
        $policies = [];
        if (Schema::hasTable('student_fee_policies') && !empty($students)) {
            $studentIds = collect($students)->pluck('id')->toArray();
            $rows = DB::table('student_fee_policies')
                ->whereIn('student_id', $studentIds)
                ->where('academic_year', (string) $cls->academic_year)
                ->get();

            foreach ($rows as $row) {
                if (!isset($policies[$row->student_id])) {
                    $policies[$row->student_id] = [];
                }
                $policies[$row->student_id][$row->fee_structure_id] = [
                    'discount_type'  => $row->discount_type,
                    'discount_value' => (float) $row->discount_value,
                    'reason'         => $row->reason ?? '',
                ];
            }
        }

        // ── Paid amounts (total per student) ──
        $payments = [];
        if (Schema::hasTable('fee_payments') && !empty($students)) {
            $studentIds = collect($students)->pluck('id')->toArray();
            $rows = DB::table('fee_payments')
                ->whereIn('student_id', $studentIds)
                ->where('academic_year', (string) $cls->academic_year)
                ->get();

            foreach ($rows as $row) {
                $payments[$row->student_id] = (float) $row->amount;
            }
        }

        // ── Payment logs (history per student) ──
        $paymentLogs = [];
        if (Schema::hasTable('fee_payment_logs') && !empty($students)) {
            $studentIds = collect($students)->pluck('id')->toArray();
            $logs = DB::table('fee_payment_logs')
                ->whereIn('student_id', $studentIds)
                ->where('academic_year', (string) $cls->academic_year)
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->get();

            foreach ($logs as $log) {
                if (!isset($paymentLogs[$log->student_id])) {
                    $paymentLogs[$log->student_id] = [];
                }
                $paymentLogs[$log->student_id][] = [
                    'id'          => (int) $log->id,
                    'amount'      => (float) $log->amount,
                    'total_after' => (float) ($log->total_after ?? 0),
                    'note'        => $log->note ?? '',
                    'paid_at'     => $log->paid_at,
                ];
            }
        }

        foreach ($students as &$student) {
            $student['policies']     = $policies[$student['id']] ?? new \stdClass();
            $student['paid_amount']  = $payments[$student['id']] ?? 0;
            $student['payment_logs'] = $paymentLogs[$student['id']] ?? [];
        }
        unset($student);

        $classInfo = [
            'id'            => $cls->id,
            'class_name'    => $cls->class_name,
            'section'       => $cls->section,
            'academic_year' => (string) $cls->academic_year,
            'class_teacher' => $cls->class_teacher,
            'subjects'      => $cls->subjects ?? [],
        ];

        return Inertia::render('fees', [
            'classes'        => $classes,
            'classInfo'      => $classInfo,
            'feeTypes'       => $feeTypes,
            'students'       => $students,
            'availableYears' => $availableYears,
            'filters'        => [
                'academic_year' => (string) $cls->academic_year,
                'search'        => $search,
            ],
            'flash'          => session()->only(['success', 'error']),
        ]);
    }

    // ─────────────────────────────────────────────
    // SAVE POLICY — student fee discount policy
    // ─────────────────────────────────────────────
    public function savePolicy(Request $request)
    {
        $validated = $request->validate([
            'student_id'                   => 'required|integer',
            'academic_year'                => 'required|string|max:10',
            'policies'                     => 'required|array',
            'policies.*.fee_structure_id'  => 'required|integer',
            'policies.*.discount_type'     => 'required|string|in:none,percent,amount,free',
            'policies.*.discount_value'    => 'required|numeric|min:0',
            'policies.*.reason'            => 'nullable|string|max:255',
        ]);

        if (! Schema::hasTable('student_fee_policies')) {
            return back()->with('error', '⚠️ `student_fee_policies` table not found. Run: php artisan migrate');
        }

        DB::transaction(function () use ($validated) {
            DB::table('student_fee_policies')
                ->where('student_id', $validated['student_id'])
                ->where('academic_year', $validated['academic_year'])
                ->delete();

            $now  = now();
            $rows = [];

            foreach ($validated['policies'] as $policy) {
                if ($policy['discount_type'] === 'none' && (float) $policy['discount_value'] <= 0) {
                    continue;
                }

                $rows[] = [
                    'student_id'       => $validated['student_id'],
                    'fee_structure_id' => $policy['fee_structure_id'],
                    'academic_year'    => $validated['academic_year'],
                    'discount_type'    => $policy['discount_type'],
                    'discount_value'   => $policy['discount_value'],
                    'reason'           => $policy['reason'] ?? null,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('student_fee_policies')->insert($rows);
            }
        });

        return back()->with('success', '✅ Fee policy updated successfully!');
    }

    // ─────────────────────────────────────────────
    // COLLECT PAYMENT — update existing row + log history
    // ─────────────────────────────────────────────
    public function collectPayment(Request $request)
    {
        $validated = $request->validate([
            'student_id'    => 'required|integer',
            'academic_year' => 'required|string|max:10',
            'amount'        => 'required|numeric|min:0.01',
            'note'          => 'nullable|string|max:255',
        ]);

        if (! Schema::hasTable('fee_payments')) {
            return back()->with('error', '⚠️ `fee_payments` table not found. Run: php artisan migrate');
        }

        try {
            DB::transaction(function () use ($validated) {
                $studentId = $validated['student_id'];
                $year      = (string) $validated['academic_year'];
                $amount    = (float) $validated['amount'];
                $now       = now();
                $userId    = auth()->id();

                // ── 1. Upsert fee_payments (one row per student + year) ──
                $existing = DB::table('fee_payments')
                    ->where('student_id', $studentId)
                    ->where('academic_year', $year)
                    ->first();

                if ($existing) {
                    $newTotal = (float) $existing->amount + $amount;

                    DB::table('fee_payments')
                        ->where('id', $existing->id)
                        ->update([
                            'amount'     => $newTotal,
                            'note'       => $validated['note'] ?? $existing->note,
                            'paid_at'    => $now,
                            'updated_at' => $now,
                        ]);
                } else {
                    $newTotal = $amount;

                    DB::table('fee_payments')->insert([
                        'student_id'    => $studentId,
                        'academic_year' => $year,
                        'amount'        => $newTotal,
                        'note'          => $validated['note'] ?? null,
                        'collected_by'  => $userId,
                        'paid_at'       => $now,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }

                // ── 2. Insert log row (history) ──
                if (Schema::hasTable('fee_payment_logs')) {
                    DB::table('fee_payment_logs')->insert([
                        'student_id'    => $studentId,
                        'academic_year' => $year,
                        'amount'        => $amount,
                        'total_after'   => $newTotal,
                        'note'          => $validated['note'] ?? null,
                        'collected_by'  => $userId,
                        'paid_at'       => $now,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', '❌ Failed to save payment: ' . $e->getMessage());
        }

        return back()->with('success', '✅ Payment collected successfully!');
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    /**
     * Build classes + available years data for a given academic year.
     */
    private function buildClassData(string $selectedYear): array
    {
        $classRows = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        $classes = $classRows->map(function ($cls) {
            $q = DB::table('students')
                ->where('class', $cls->class_name)
                ->where('academic_year', (string) $cls->academic_year)
                ->where('status', 'active');

            if ($cls->section) {
                $q->where('section', $cls->section);
            } else {
                $q->where(function ($w) {
                    $w->whereNull('section')->orWhere('section', '');
                });
            }

            $studentCount = $q->count();

            $feeCount = 0;
            if (Schema::hasTable('fee_structures')) {
                $feeCount = DB::table('fee_structures')
                    ->where('class_id', $cls->id)
                    ->where('academic_year', (string) $cls->academic_year)
                    ->where('is_active', 1)
                    ->distinct('fee_name')
                    ->count('fee_name');
            }

            return [
                'id'            => $cls->id,
                'class_name'    => $cls->class_name,
                'section'       => $cls->section,
                'academic_year' => (string) $cls->academic_year,
                'class_teacher' => $cls->class_teacher,
                'subjects'      => $cls->subjects ?? [],
                'student_count' => $studentCount,
                'fee_count'     => $feeCount,
            ];
        })->values()->toArray();

        $availableYears = SchoolClass::query()
            ->where('status', 'active')
            ->whereNotNull('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->map(fn ($y) => (string) $y)
            ->values()
            ->toArray();

        $currentYear = (string) now()->year;
        if (!in_array($currentYear, $availableYears, true)) {
            $availableYears[] = $currentYear;
            rsort($availableYears);
        }

        return [$classes, $availableYears];
    }

    /**
     * Build fee types for a class.
     */
    private function buildFeeTypes($cls): array
    {
        if (! Schema::hasTable('fee_structures')) {
            return [];
        }

        $rows = DB::table('fee_structures')
            ->where('class_id', $cls->id)
            ->where('academic_year', (string) $cls->academic_year)
            ->where('is_active', 1)
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            if (!isset($grouped[$row->fee_name])) {
                $grouped[$row->fee_name] = [
                    'id'     => (int) $row->id,
                    'name'   => $row->fee_name,
                    'amount' => (float) $row->amount,
                    'cycle'  => $row->cycle,
                ];
            }
        }

        return array_values($grouped);
    }
}