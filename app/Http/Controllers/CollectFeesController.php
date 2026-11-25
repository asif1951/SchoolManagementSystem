<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CollectFeesController extends Controller
{
    // ─────────────────────────────────────────────
    // INDEX — Class list
    // ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $selectedYear = (string) ($request->input('academic_year') ?? now()->year);

        // 🆕 Class / Section filters
        $selectedClassName = (string) $request->input('class_name', '');
        $selectedSection   = (string) $request->input('section', '');

        [$classes, $availableYears] = $this->buildClassData($selectedYear, $selectedClassName, $selectedSection);

        // 🆕 Dropdown options (based on selected year / class)
        [$availableClassNames, $availableSections, $hasNoSection] = $this->buildFilterOptions($selectedYear, $selectedClassName);

        return Inertia::render('fees', [
            'classes'             => $classes,
            'classInfo'           => null,
            'feeTypes'            => [],
            'students'            => [],
            'availableYears'      => $availableYears,
            'availableClassNames' => $availableClassNames, // 🆕
            'availableSections'   => $availableSections,   // 🆕
            'hasNoSection'        => $hasNoSection,        // 🆕
            'filters'             => [
                'academic_year' => $selectedYear,
                'search'        => '',
                'class_name'    => $selectedClassName,     // 🆕
                'section'       => $selectedSection,       // 🆕
            ],
            'flash'               => session()->only(['success', 'error']),
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
            ->whereNull('deleted_at')
            ->where('class_id', $cls->id)
            ->where('status', 'active');

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
            ->map(function ($student) use ($cls) {
                return [
                    'id'          => $student->id,
                    'name'        => $student->student_name,
                    'student_id'  => $student->student_id,
                    'class'       => $cls->class_name ?? 'N/A',
                    'section'     => $cls->section ?: 'N/A',
                    'roll_number' => $student->roll_number,
                    'photo'       => $student->student_photo ?? null,
                ];
            })
            ->toArray();

        // ── Existing policies ──
        $policies = [];
        if (!empty($students)) {
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
        if (!empty($students)) {
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
        if (!empty($students)) {
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
            'class_label' => $cls->class_label,
            'section_label' => $cls->section_label,
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
            'student_id'                   => 'required|integer|exists:students,id',
            'academic_year'                => 'required|string|max:10',
            'policies'                     => 'required|array|max:100',
            'policies.*.fee_structure_id'  => 'required|integer|exists:fee_structures,id',
            'policies.*.discount_type'     => 'required|string|in:none,percent,amount,free',
            'policies.*.discount_value'    => 'required|numeric|min:0|max:99999999',
            'policies.*.reason'            => 'nullable|string|max:255',
        ]);

        $student = Student::findOrFail($validated['student_id']);

        if ((string) $student->academic_year !== (string) $validated['academic_year']) {
            return back()->with('error', 'Academic year does not match the student.');
        }

        // Every fee structure must belong to the student's own class.
        $structureIds = collect($validated['policies'])->pluck('fee_structure_id')->unique();
        $validStructures = DB::table('fee_structures')
            ->whereIn('id', $structureIds)
            ->where('class_id', $student->class_id)
            ->count();

        if ($validStructures !== $structureIds->count()) {
            return back()->with('error', 'One or more fees do not belong to this student\'s class.');
        }

        foreach ($validated['policies'] as $policy) {
            if ($policy['discount_type'] === 'percent' && (float) $policy['discount_value'] > 100) {
                return back()->with('error', 'A percentage discount cannot exceed 100.');
            }
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
            'student_id'    => 'required|integer|exists:students,id',
            'academic_year' => 'required|string|max:10',
            'amount'        => 'required|numeric|min:0.01|max:99999999',
            'note'          => 'nullable|string|max:255',
        ]);

        $student = Student::findOrFail($validated['student_id']);

        if ((string) $student->academic_year !== (string) $validated['academic_year']) {
            return back()->with('error', 'Academic year does not match the student.');
        }

        try {
            DB::transaction(function () use ($validated) {
                $studentId = (int) $validated['student_id'];
                $year      = (string) $validated['academic_year'];
                $amount    = round((float) $validated['amount'], 2);
                $now       = now();
                $userId    = auth()->id();

                // One row per student + year (unique key). Create it empty if missing,
                // then lock it so concurrent payments are applied one after another.
                DB::table('fee_payments')->insertOrIgnore([
                    'student_id'    => $studentId,
                    'academic_year' => $year,
                    'amount'        => 0,
                    'collected_by'  => $userId,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);

                $row = DB::table('fee_payments')
                    ->where('student_id', $studentId)
                    ->where('academic_year', $year)
                    ->lockForUpdate()
                    ->first();

                // Exact decimal addition done by the database, not PHP floats.
                DB::table('fee_payments')
                    ->where('id', $row->id)
                    ->update([
                        'amount'     => DB::raw('amount + ' . number_format($amount, 2, '.', '')),
                        'note'       => $validated['note'] ?? $row->note,
                        'paid_at'    => $now,
                        'updated_at' => $now,
                    ]);

                $newTotal = (float) DB::table('fee_payments')->where('id', $row->id)->value('amount');

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
            });
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', '❌ Failed to save payment. Please try again.');
        }

        return back()->with('success', '✅ Payment collected successfully!');
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    /**
     * Build classes + available years data for a given academic year.
     * 🆕 Optional $className / $section backend filters ('__none__' = no section).
     */
    private function buildClassData(string $selectedYear, string $className = '', string $section = ''): array
    {
        $classQuery = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section');

        // 🆕 Class filter
        if ($className !== '') {
            $classQuery->where('class_name', $className);
        }

        // 🆕 Section filter
        if ($section !== '') {
            if ($section === '__none__') {
                $classQuery->where(function ($w) {
                    $w->whereNull('section')->orWhere('section', '');
                });
            } else {
                $classQuery->where('section', $section);
            }
        }

        $classRows = $classQuery->get();

        $classIds = $classRows->pluck('id');

        $studentCounts = DB::table('students')
            ->whereNull('deleted_at')
            ->whereIn('class_id', $classIds)
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) AS total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $feeCounts = DB::table('fee_structures')
            ->whereIn('class_id', $classIds)
            ->where('is_active', 1)
            ->selectRaw('class_id, COUNT(DISTINCT fee_name) AS total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $classes = $classRows->map(function ($cls) use ($studentCounts, $feeCounts) {
            $studentCount = (int) ($studentCounts[$cls->id] ?? 0);
            $feeCount     = (int) ($feeCounts[$cls->id] ?? 0);

            return [
                'id'            => $cls->id,
                'class_name'    => $cls->class_name,
                'class_label' => $cls->class_label,
                'section_label' => $cls->section_label,
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
     * 🆕 Build Class / Section dropdown options for the selected year.
     * Sections are narrowed by the selected class (if any).
     *
     * @return array [classNames[], sections[], hasNoSection(bool)]
     */
    private function buildFilterOptions(string $selectedYear, string $className = ''): array
    {
        $base = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear);

        $classNames = (clone $base)
            ->select('class_name')
            ->distinct()
            ->orderBy('class_name')
            ->pluck('class_name')
            ->map(fn ($c) => (string) $c)
            ->values()
            ->toArray();

        $sectionBase = clone $base;
        if ($className !== '') {
            $sectionBase->where('class_name', $className);
        }

        $sections = (clone $sectionBase)
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->select('section')
            ->distinct()
            ->orderBy('section')
            ->pluck('section')
            ->map(fn ($s) => (string) $s)
            ->values()
            ->toArray();

        $hasNoSection = (clone $sectionBase)
            ->where(function ($w) {
                $w->whereNull('section')->orWhere('section', '');
            })
            ->exists();

        return [$classNames, $sections, $hasNoSection];
    }

    /**
     * Build fee types for a class.
     */
    private function buildFeeTypes($cls): array
    {
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