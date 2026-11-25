<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Notice;
use App\Models\WebsiteEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * How long the (heavy) year statistics are cached. Short enough that the
     * numbers stay fresh, long enough that refreshing the page costs 1 query.
     */
    private const CACHE_SECONDS = 60;

    public function index(Request $request)
    {
        $user = Auth::user();
        $isAdmin = $user?->role === 'admin';

        // ============================================================
        // ✅ SELECTED YEAR (default = current year, can be changed)
        // ============================================================
        $currentYear = (int) now()->year;

        $selectedYear = (int) $request->input('year', $currentYear);
        if ($selectedYear < 2000 || $selectedYear > 2100) {
            $selectedYear = $currentYear;
        }

        // Year statistics are identical for every admin, so compute them once.
        $payload = Cache::remember(
            "dashboard.payload.{$selectedYear}.{$currentYear}",
            self::CACHE_SECONDS,
            fn () => $this->buildPayload($selectedYear, $currentYear)
        );

        return Inertia::render('Dashboard', [
            'isAdmin' => $isAdmin,

            // Per-user part — never cached.
            'user' => [
                'id'    => $user?->id,
                'name'  => $user?->name,
                'email' => $user?->email,
                'role'  => $user?->role,
            ],
        ] + $payload);
    }

    /**
     * All year-dependent dashboard data (no per-user values in here).
     */
    private function buildPayload(int $selectedYear, int $currentYear): array
    {

        // ============================================================
        // ✅ HELPER: Dynamic months calculation (for fees & discounts)
        // ============================================================
        $getMonthsElapsed = function ($academicYear) {
            $now = now();
            $currentYear = $now->year;
            $currentMonth = $now->month - 1; // 0 = Jan, 9 = Oct
            $startYear = (int) $academicYear ?: $currentYear;
            $academicStartMonth = 0; // 0 = January (same as Fees page)

            if ($currentYear === $startYear) {
                return max(1, $currentMonth - $academicStartMonth + 1);
            }
            if ($currentYear > $startYear) {
                return 12;
            }
            return 1;
        };

        // ============================================================
        // ✅ AVAILABLE YEARS
        // ============================================================
        $availableYears = collect();

        $availableYears = $availableYears->merge(
            SchoolClass::query()
                ->whereNotNull('academic_year')
                ->distinct()
                ->pluck('academic_year')
        );

        $availableYears = $availableYears->merge(
            FeeStructure::query()
                ->whereNotNull('academic_year')
                ->distinct()
                ->pluck('academic_year')
        );

        $availableYears = $availableYears->merge(
            FeePayment::query()
                ->whereNotNull('academic_year')
                ->distinct()
                ->pluck('academic_year')
        );

        $availableYears = $availableYears->merge(
            Notice::query()
                ->whereNotNull('notice_date')
                ->selectRaw('YEAR(notice_date) as yr')
                ->distinct()
                ->pluck('yr')
        );

        $availableYears = $availableYears->merge(
            WebsiteEvent::query()
                ->whereNotNull('event_date')
                ->selectRaw('YEAR(event_date) as yr')
                ->distinct()
                ->pluck('yr')
        );

        $availableYears = $availableYears
            ->filter()
            ->map(fn($y) => (int) $y)
            ->unique()
            ->sortDesc()
            ->values();

        if (! $availableYears->contains($currentYear)) {
            $availableYears = $availableYears->prepend($currentYear)->values();
        }
        if (! $availableYears->contains($selectedYear)) {
            $availableYears = $availableYears->prepend($selectedYear)->values();
        }
        $availableYears = $availableYears->unique()->sortDesc()->values();

        // ============================================================
        // ✅ CORE STATS
        // ============================================================
        $totalStudents  = Student::where('status', 'active')->where('academic_year', (string) $selectedYear)->count();

        $totalTeachers  = Teacher::where('status', 'active')->count();
        $totalClasses   = SchoolClass::where('status', 'active')->where('academic_year', (string) $selectedYear)->count();
        $totalUsers     = User::where('status', 'active')->count();

        // ============================================================
        // ✅ EXTRA STATS
        // ============================================================
        $activeClasses = SchoolClass::where('status', 'active')->where('academic_year', (string) $selectedYear)->count();

        $studentsThisMonth = Student::where('status', 'active')
            ->where('academic_year', (string) $selectedYear)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $teachersThisMonth = Teacher::where('status', 'active')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $paymentsToday = FeePayment::where('academic_year', (string) $selectedYear)
            ->whereDate('paid_at', now()->toDateString())
            ->count();

        // ============================================================
        // ✅ FEE / FINANCE STATS
        // ============================================================
        $totalFeesCollected = (float) FeePayment::where('academic_year', (string) $selectedYear)->sum('amount');

        $totalFeeStructures = FeeStructure::where('is_active', 1)->where('academic_year', (string) $selectedYear)->count();

        $feesThisYear = $totalFeesCollected;

        // ============================================================
        // ✅ SELECTED YEAR FEE TOTAL (ANNUALIZED)
        // ============================================================
        $currentYearFeeTotal = 0;
        $feeStructuresCollection = collect();
        $monthsElapsed = $getMonthsElapsed($selectedYear);
        $feeStructuresCollection = FeeStructure::where('is_active', 1)
            ->where('academic_year', (string) $selectedYear)
            ->get();
        foreach ($feeStructuresCollection as $f) {
            $cycle  = trim((string) $f->cycle);
            $amount = (float) $f->amount;

            $multiplier = match (strtolower($cycle)) {
                'monthly'      => $monthsElapsed, // ✅ Dynamic months (10 or 12)
                'quarterly'    => 4,
                'half-yearly',
                'half yearly'  => 2,
                'term-wise',
                'term wise'    => 3,
                'yearly',
                'annual'       => 1,
                'one-time',
                'one time'     => 1,
                default        => 1,
            };

            $currentYearFeeTotal += $amount * $multiplier;
        }

        // ============================================================
        // ✅ NET PAYABLE (AFTER DISCOUNT) — ✅ NEW
        // ✅ Discount শুধু MONTHLY fee structure এর উপরে apply হবে
        // ============================================================
        $totalDiscount      = 0;
        $discountedStudents = 0;
        $policies = DB::table('student_fee_policies')
            ->where('academic_year', (string) $selectedYear)
            ->get();
        // Build a map: fee_structure_id => structure info
        $structureMap = $feeStructuresCollection->keyBy('id')->map(function ($f) use ($monthsElapsed) {
            $cycle = strtolower(trim((string) $f->cycle));
            $multiplier = match ($cycle) {
                'monthly'      => $monthsElapsed, // ✅ Dynamic months
                'quarterly'    => 4,
                'half-yearly',
                'half yearly'  => 2,
                'term-wise',
                'term wise'    => 3,
                'yearly',
                'annual'       => 1,
                'one-time',
                'one time'     => 1,
                default        => 1,
            };
            return [
                'id'         => $f->id,
                'amount'     => (float) $f->amount,
                'cycle'      => $cycle,
                'multiplier' => $multiplier,
                'annual'     => (float) $f->amount * $multiplier,
                'is_monthly' => $cycle === 'monthly',  // ✅ only monthly flag
            ];
        });
        foreach ($policies as $policy) {
            $structure = $structureMap->get($policy->fee_structure_id);
            if (! $structure) continue;

            // ✅✅ ONLY MONTHLY fee structures get discount
            if (! $structure['is_monthly']) continue;

            $annualAmount = $structure['annual'];   // annualized monthly fee
            $type         = strtolower(trim((string) $policy->discount_type));
            $value        = (float) $policy->discount_value;

            if ($type === 'percent' || $type === 'percentage') {
                $totalDiscount += ($annualAmount * $value / 100);
            } else {
                // fixed / flat
                $totalDiscount += $value;
            }
        }
        // ✅ discounted students গোনা হবে শুধু monthly fee policy যাদের আছে
        $discountedStudents = $policies
            ->filter(function ($policy) use ($structureMap) {
                $structure = $structureMap->get($policy->fee_structure_id);
                return $structure && $structure['is_monthly'];
            })
            ->pluck('student_id')
            ->unique()
            ->count();

        $netPayable = max(0, $currentYearFeeTotal - $totalDiscount);

        // ============================================================
        // ✅ MONTHLY FEE COLLECTION
        // ============================================================
        $monthlyFees = [];
        $raw = FeePayment::query()
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as ym, SUM(amount) as total")
            ->whereNotNull('paid_at')
            ->where('academic_year', (string) $selectedYear)
            ->groupBy('ym')
            ->orderBy('ym')
            ->limit(12)
            ->get();
        $monthlyFees = $raw->map(fn($r) => [
            'month' => $r->ym,
            'label' => \Carbon\Carbon::createFromFormat('Y-m', $r->ym)->format('M Y'),
            'total' => (float) $r->total,
        ])->toArray();

        // ============================================================
        // ✅ STUDENTS PER CLASS
        // ============================================================
        $studentsByClass = [];
        $classes = SchoolClass::where('status', 'active')
            ->where('academic_year', (string) $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section')
            ->get(['id', 'class_name', 'section']);
        // One grouped query instead of one count per class.
        $classCounts = Student::query()
            ->whereIn('class_id', $classes->pluck('id'))
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) AS total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');
        $studentsByClass = $classes->map(function ($c) use ($classCounts) {
            $count = (int) ($classCounts[$c->id] ?? 0);

            return [
                'id'         => $c->id,
                'class_name' => $c->class_name,
                'class_label' => $c->class_label,
                'section_label' => $c->section_label,
                'section'    => $c->section ?? '—',
                'label'      => $c->full_label,
                'count'      => $count,
            ];
        })->toArray();

        // ============================================================
        // ✅ RECENT STUDENTS
        // ============================================================
        $recentStudents = [];
        $recentStudents = Student::with('schoolClass:id,class_name,section')
            ->where('academic_year', (string) $selectedYear)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($s) => [
                'id'            => $s->id,
                'student_name'  => $s->student_name,
                'student_id'    => $s->student_id,
                'student_photo' => $s->student_photo,
                'class'         => $s->schoolClass?->class_name,
                'section'       => $s->schoolClass?->section,
                'roll_number'   => $s->roll_number,
                'created_at'    => optional($s->created_at)->toISOString(),
            ])
            ->toArray();

        // ============================================================
        // ✅ RECENT FEE PAYMENTS
        // ============================================================
        $recentPayments = [];
        $recentPayments = FeePayment::with('student:id,student_name,student_id,class_id', 'student.schoolClass:id,class_name')
            ->where('academic_year', (string) $selectedYear)
            ->orderByDesc('paid_at')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'id'            => $p->id,
                'amount'        => (float) $p->amount,
                'paid_at'       => optional($p->paid_at)->toISOString(),
                'academic_year' => $p->academic_year,
                'student_name'  => $p->student?->student_name ?? '—',
                'student_code'  => $p->student?->student_id ?? '—',
                'class'         => $p->student?->schoolClass?->class_name ?? '—',
            ])
            ->toArray();

        // ============================================================
        // ✅ RECENT NOTICES — YEAR-BASED
        // ============================================================
        $recentNotices = [];
        $recentNotices = Notice::where('status', 'published')
            ->whereYear('notice_date', $selectedYear)
            ->orderByDesc('notice_date')
            ->limit(4)
            ->get()
            ->map(fn($n) => [
                'id'          => $n->id,
                'title'       => $n->title,
                'description' => $n->description,
                'notice_date' => optional($n->notice_date)->toDateString(),
            ])
            ->toArray();

        // ============================================================
        // ✅ UPCOMING EVENTS — YEAR-BASED
        // ============================================================
        $upcomingEvents = [];
        $upcomingEvents = WebsiteEvent::where('status', 'upcoming')
            ->whereYear('event_date', $selectedYear)
            ->orderBy('event_date')
            ->limit(4)
            ->get()
            ->map(fn($e) => [
                'id'          => $e->id,
                'title'       => $e->title,
                'event_date'  => optional($e->event_date)->toDateString(),
                'event_time'  => $e->event_time,
                'location'    => $e->location,
                'event_image' => $e->event_image,
            ])
            ->toArray();

        // ============================================================
        // ✅ TOP TEACHERS
        // ============================================================
        $topTeachers = [];
        $topTeachers = Teacher::where('status', 'active')
            ->orderBy('order')
            ->limit(4)
            ->get()
            ->map(fn($t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'photo'       => $t->photo,
                'designation' => $t->designation,
                'department'  => $t->department,
            ])
            ->toArray();

        // ============================================================
        // ✅ FEE STRUCTURE SUMMARY
        // ============================================================
        $feeSummary = [];
        $feeSummary = FeeStructure::where('is_active', 1)
            ->where('academic_year', (string) $selectedYear)
            ->orderBy('class_id')
            ->orderBy('fee_name')
            ->limit(5)
            ->get()
            ->map(fn($f) => [
                'id'            => $f->id,
                'fee_name'      => $f->fee_name,
                'amount'        => (float) $f->amount,
                'cycle'         => $f->cycle,
                'class_id'      => $f->class_id,
                'academic_year' => $f->academic_year,
            ])
            ->toArray();

        // ============================================================
        // ✅ RECENT ACTIVITIES — YEAR-BASED
        // ============================================================
        $recentActivities = [];

        if (!empty($recentPayments[0])) {
            $p = $recentPayments[0];
            $recentActivities[] = [
                'type'     => 'payment',
                'title'    => 'New fee payment received',
                'subtitle' => $p['student_name'] . ' paid ৳' . number_format($p['amount'], 2),
                'time'     => $p['paid_at'] ? \Carbon\Carbon::parse($p['paid_at'])->diffForHumans() : '—',
            ];
        }

        if (!empty($recentNotices[0])) {
            $n = $recentNotices[0];
            $recentActivities[] = [
                'type'     => 'notice',
                'title'    => 'Notice published',
                'subtitle' => $n['title'],
                'time'     => $n['notice_date'] ? \Carbon\Carbon::parse($n['notice_date'])->diffForHumans() : '—',
            ];
        }
        $latestClass = SchoolClass::where('academic_year', (string) $selectedYear)
            ->orderByDesc('created_at')
            ->first();
        if ($latestClass) {
            $recentActivities[] = [
                'type'     => 'class',
                'title'    => 'New class added',
                'subtitle' => $latestClass->class_name
                    . ($latestClass->section ? ' (' . $latestClass->section . ')' : '')
                    . ' - ' . $latestClass->academic_year,
                'time'     => optional($latestClass->created_at)->diffForHumans() ?? '—',
            ];
        }
        $latestTeacher = Teacher::whereYear('created_at', $selectedYear)
            ->orderByDesc('created_at')
            ->first();
        if ($latestTeacher) {
            $recentActivities[] = [
                'type'     => 'teacher',
                'title'    => 'Teacher added',
                'subtitle' => $latestTeacher->name,
                'time'     => optional($latestTeacher->created_at)->diffForHumans() ?? '—',
            ];
        }
        $latestStudent = Student::with('schoolClass:id,class_name')
            ->where('academic_year', (string) $selectedYear)
            ->orderByDesc('created_at')
            ->first();
        if ($latestStudent) {
            $recentActivities[] = [
                'type'     => 'student',
                'title'    => 'Student admitted',
                'subtitle' => $latestStudent->student_name . ' — Class ' . ($latestStudent->schoolClass?->class_name ?? '—'),
                'time'     => optional($latestStudent->created_at)->diffForHumans() ?? '—',
            ];
        }
        $latestEvent = WebsiteEvent::whereYear('event_date', $selectedYear)
            ->orderByDesc('created_at')
            ->first();
        if ($latestEvent) {
            $recentActivities[] = [
                'type'     => 'event',
                'title'    => 'Event scheduled',
                'subtitle' => $latestEvent->title,
                'time'     => optional($latestEvent->created_at)->diffForHumans() ?? '—',
            ];
        }

        $recentActivities = array_slice($recentActivities, 0, 5);

        // ============================================================
        // ✅ PASS / FAIL QUICK STATS
        // ============================================================
        $passCount = 0;
        $failCount = 0;
        $passCount = DB::table('results')
            ->where('academic_year', (string) $selectedYear)
            ->where('grade', '!=', 'F')
            ->distinct('student_id')
            ->count('student_id');
        $failCount = DB::table('results')
            ->where('academic_year', (string) $selectedYear)
            ->where('grade', 'F')
            ->distinct('student_id')
            ->count('student_id');

        return [
            'selectedYear'   => $selectedYear,
            'currentYear'    => $currentYear,
            'availableYears' => $availableYears,

            'stats' => [
                'totalStudents'      => $totalStudents,
                'totalTeachers'      => $totalTeachers,
                'totalClasses'       => $totalClasses,
                'totalUsers'         => $totalUsers,

                'activeClasses'      => $activeClasses,
                'studentsThisMonth'  => $studentsThisMonth,
                'teachersThisMonth'  => $teachersThisMonth,
                'paymentsToday'      => $paymentsToday,

                'totalFeesCollected' => $totalFeesCollected,
                'totalFeeStructures' => $totalFeeStructures,
                'feesThisYear'       => $feesThisYear,
                'currentYearFeeTotal' => $currentYearFeeTotal,

                // ✅ Discount + Net payable (monthly only)
                'totalDiscount'      => $totalDiscount,
                'netPayable'         => $netPayable,
                'discountedStudents' => $discountedStudents,

                'passCount'          => $passCount,
                'failCount'          => $failCount,
            ],

            'monthlyFees'       => $monthlyFees,
            'studentsByClass'   => $studentsByClass,
            'recentStudents'    => $recentStudents,
            'recentPayments'    => $recentPayments,
            'recentNotices'     => $recentNotices,
            'upcomingEvents'    => $upcomingEvents,
            'topTeachers'       => $topTeachers,
            'feeSummary'        => $feeSummary,
            'recentActivities'  => $recentActivities,
        ];
    }
}