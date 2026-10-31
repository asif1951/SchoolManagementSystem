<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    /**
     * Main attendance page
     * - Admin: all active classes
     * - Teacher: only their own classes
     * - ✅ Academic Year filter + Class filter + Section filter + Pagination
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';
        $teacherName = $user->name;

        // ✅ Selected academic year (default: current year)
        $selectedYear = (string) $request->input('academic_year', now()->year);

        // ⭐ Ensure classes exist for this year (auto-copy from most recent year)
        $this->ensureClassesForYear((int) $selectedYear);

        // ✅ Available years from classes table
        $availableYears = SchoolClass::query()
            ->where('status', 'active')
            ->select('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->map(fn ($y) => (string) $y)
            ->toArray();

        if (empty($availableYears)) {
            $availableYears = [(string) now()->year];
        }

        // 🆕 Selected class / section filters
        $selectedClassName = (string) $request->input('class_name', '');
        $selectedSection = (string) $request->input('section', '');

        // 🆕 Options for Class / Section dropdowns (year + teacher scope aware)
        $optionsBase = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear);

        if (! $isAdmin) {
            $optionsBase->where('class_teacher', $teacherName);
        }

        $availableClassNames = (clone $optionsBase)
            ->select('class_name')
            ->distinct()
            ->orderBy('class_name')
            ->pluck('class_name')
            ->map(fn ($c) => (string) $c)
            ->values()
            ->toArray();

        $sectionOptionsQuery = clone $optionsBase;
        if ($selectedClassName !== '') {
            $sectionOptionsQuery->where('class_name', $selectedClassName);
        }

        $availableSections = (clone $sectionOptionsQuery)
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->select('section')
            ->distinct()
            ->orderBy('section')
            ->pluck('section')
            ->map(fn ($s) => (string) $s)
            ->values()
            ->toArray();

        $hasNoSection = (clone $sectionOptionsQuery)
            ->where(function ($q) {
                $q->whereNull('section')->orWhere('section', '');
            })
            ->exists();

        // ✅ Class query — filtered by selected year
        $query = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section');

        if (! $isAdmin) {
            $query->where('class_teacher', $teacherName);
        }

        // 🆕 Class filter (backend)
        if ($selectedClassName !== '') {
            $query->where('class_name', $selectedClassName);
        }

        // 🆕 Section filter (backend) — '__none__' = classes without section
        if ($selectedSection !== '') {
            if ($selectedSection === '__none__') {
                $query->where(function ($q) {
                    $q->whereNull('section')->orWhere('section', '');
                });
            } else {
                $query->where('section', $selectedSection);
            }
        }

        // ✅ Search filter (backend)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('class_name', 'LIKE', "%{$search}%")
                  ->orWhere('section', 'LIKE', "%{$search}%")
                  ->orWhere('class_teacher', 'LIKE', "%{$search}%");
            });
        }

        // ✅ Pagination — 12 classes per page
        $paginatedClasses = $query->paginate(12)->withQueryString();

        // ✅ Transform each class with attendance stats
        $classesData = collect($paginatedClasses->items())->map(function ($class) use ($isAdmin) {
            return $this->formatClassData($class, $isAdmin);
        });

        // ✅ Rebuild paginator
        $teacherClasses = [
            'data' => $classesData,
            'links' => $paginatedClasses->linkCollection()->toArray(),
            'current_page' => $paginatedClasses->currentPage(),
            'last_page' => $paginatedClasses->lastPage(),
            'from' => $paginatedClasses->firstItem(),
            'to' => $paginatedClasses->lastItem(),
            'total' => $paginatedClasses->total(),
            'per_page' => $paginatedClasses->perPage(),
        ];

        return Inertia::render('attendance', [
            'teacherClasses' => $teacherClasses,
            'classInfo' => null,
            'students' => [
                'data' => [],
                'links' => [],
                'current_page' => 1,
                'last_page' => 1,
                'from' => 0,
                'to' => 0,
                'total' => 0,
            ],
            'summary' => [
                'total' => 0, 'present' => 0, 'absent' => 0,
                'late' => 0, 'leave' => 0, 'percentage' => 0,
            ],
            'selectedDate' => $request->input('date', today()->toDateString()),
            'isAdmin' => $isAdmin,
            'availableYears' => $availableYears,
            'availableClassNames' => $availableClassNames,   // 🆕
            'availableSections' => $availableSections,       // 🆕
            'hasNoSection' => $hasNoSection,                 // 🆕
            'selectedYear' => $selectedYear,
            'filters' => [
                'search' => $request->input('search', ''),
                'academic_year' => $selectedYear,
                'class_name' => $selectedClassName,          // 🆕
                'section' => $selectedSection,               // 🆕
            ],
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    /**
     * Class click → students with pagination (40 per page)
     */
    public function show(Request $request, $classId)
    {
        $class = SchoolClass::findOrFail($classId);

        // ⭐ Ensure classes exist for this class's year (safety)
        $this->ensureClassesForYear((int) $class->academic_year);

        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        if (! $isAdmin && $class->class_teacher !== $user->name) {
            abort(403, 'You are not authorized for this class.');
        }

        $selectedYear = (string) $class->academic_year;

        // ✅ Sidebar class list (with same pagination)
        $query = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section');

        if (! $isAdmin) {
            $query->where('class_teacher', $user->name);
        }

        $paginatedClasses = $query->paginate(12)->withQueryString();

        $classesData = collect($paginatedClasses->items())->map(function ($c) use ($isAdmin) {
            return $this->formatClassData($c, $isAdmin);
        });

        $teacherClasses = [
            'data' => $classesData,
            'links' => $paginatedClasses->linkCollection()->toArray(),
            'current_page' => $paginatedClasses->currentPage(),
            'last_page' => $paginatedClasses->lastPage(),
            'from' => $paginatedClasses->firstItem(),
            'to' => $paginatedClasses->lastItem(),
            'total' => $paginatedClasses->total(),
            'per_page' => $paginatedClasses->perPage(),
        ];

        $date = $request->input('date', today()->toDateString());

        // ✅ Students query with search filter — section-strict NULL-safe
        $studentsQuery = Student::query()
            ->where('class', $class->class_name)
            ->where('academic_year', $class->academic_year)
            ->where('status', 'active');

        if ($class->section === null || $class->section === '') {
            $studentsQuery->where(function ($q) {
                $q->whereNull('section')->orWhere('section', '');
            });
        } else {
            $studentsQuery->where('section', $class->section);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $studentsQuery->where(function ($q) use ($search) {
                $q->where('student_name', 'LIKE', "%{$search}%")
                  ->orWhere('student_id', 'LIKE', "%{$search}%")
                  ->orWhere('roll_number', 'LIKE', "%{$search}%");
            });
        }

        // ✅ Pagination — 40 students per page
        $paginatedStudents = $studentsQuery
            ->orderBy('roll_number')
            ->orderBy('student_name')
            ->paginate(40)
            ->withQueryString();

        // ✅ Page students only for stats
        $pageStudents = collect($paginatedStudents->items());
        $studentIds = $pageStudents->pluck('id')->toArray();

        // ✅ Attendance stats per student (for page students)
        $attendanceStats = collect();
        if (! empty($studentIds)) {
            $attendanceStats = Attendance::query()
                ->whereIn('student_id', $studentIds)
                ->where('academic_year', $class->academic_year)
                ->selectRaw("
                    student_id,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days
                ")
                ->groupBy('student_id')
                ->get()
                ->keyBy('student_id');
        }

        // ✅ Existing attendance for the date
        $existingAttendance = collect();
        if (! empty($studentIds)) {
            $existingAttendance = Attendance::whereIn('student_id', $studentIds)
                ->whereDate('attendance_date', $date)
                ->get()
                ->keyBy('student_id');
        }

        $studentsData = $pageStudents->map(function ($student) use ($existingAttendance, $attendanceStats) {
            $existing = $existingAttendance->get($student->id);
            $stats = $attendanceStats->get($student->id);

            $totalDays = $stats->total_days ?? 0;
            $presentDays = $stats->present_days ?? 0;
            $absentDays = $stats->absent_days ?? 0;
            $lateDays = $stats->late_days ?? 0;
            $leaveDays = $stats->leave_days ?? 0;

            $attendedDays = $presentDays + $lateDays;
            $percentage = $totalDays > 0
                ? round(($attendedDays / $totalDays) * 100, 1)
                : 0;

            return [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'student_name' => $student->student_name,
                'student_photo' => $student->student_photo,
                'roll_number' => $student->roll_number,
                'status' => $existing?->status ?? 'present',
                'already_marked' => (bool) $existing,
                'stats' => [
                    'total_days' => $totalDays,
                    'present_days' => $presentDays,
                    'absent_days' => $absentDays,
                    'late_days' => $lateDays,
                    'leave_days' => $leaveDays,
                    'percentage' => $percentage,
                ],
            ];
        });

        // ✅ Paginator for students
        $studentsPaginated = [
            'data' => $studentsData,
            'links' => $paginatedStudents->linkCollection()->toArray(),
            'current_page' => $paginatedStudents->currentPage(),
            'last_page' => $paginatedStudents->lastPage(),
            'from' => $paginatedStudents->firstItem(),
            'to' => $paginatedStudents->lastItem(),
            'total' => $paginatedStudents->total(),
            'per_page' => $paginatedStudents->perPage(),
        ];

        // ✅ Summary for current page
        $summary = [
            'total' => $studentsData->count(),
            'present' => $studentsData->where('status', 'present')->count(),
            'absent' => $studentsData->where('status', 'absent')->count(),
            'late' => $studentsData->where('status', 'late')->count(),
            'leave' => $studentsData->where('status', 'leave')->count(),
        ];

        $summary['percentage'] = $summary['total'] > 0
            ? round(($summary['present'] / $summary['total']) * 100, 1)
            : 0;

        return Inertia::render('attendance', [
            'teacherClasses' => $teacherClasses,
            'classInfo' => [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'section' => $class->section,
                'academic_year' => (string) $class->academic_year,
                'class_teacher' => $class->class_teacher,
            ],
            'students' => $studentsPaginated,
            'summary' => $summary,
            'selectedDate' => $date,
            'isAdmin' => $isAdmin,
            'availableYears' => SchoolClass::query()
                ->where('status', 'active')
                ->select('academic_year')
                ->distinct()
                ->orderByDesc('academic_year')
                ->pluck('academic_year')
                ->map(fn ($y) => (string) $y)
                ->toArray(),
            'selectedYear' => $selectedYear,
            'filters' => [
                'search' => $request->input('search', ''),
                'academic_year' => $selectedYear,
            ],
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    /**
     * Save attendance
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'date' => 'required|date',
            'attendance' => 'required|array',
            'attendance.*.student_id' => 'required|integer|exists:students,id',
            'attendance.*.status' => 'required|in:present,absent,late,leave',
        ]);

        $class = SchoolClass::findOrFail($validated['class_id']);
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        if (! $isAdmin && $class->class_teacher !== $user->name) {
            return back()->with('error', 'Unauthorized action.');
        }

        foreach ($validated['attendance'] as $record) {
            Attendance::updateOrCreate(
                [
                    'student_id' => $record['student_id'],
                    'attendance_date' => $validated['date'],
                ],
                [
                    'class' => $class->class_name,
                    'section' => $class->section,
                    'status' => $record['status'],
                    'academic_year' => $class->academic_year,
                    'marked_by' => Auth::id(),
                ]
            );
        }

        return redirect()
            ->route('attendance.show', $class->id)
            ->with('success', 'Attendance saved successfully!');
    }

    /**
     * Helper: format class data — section-strict NULL-safe student count
     */
    private function formatClassData($class, $isAdmin = false)
    {
        $studentQuery = Student::query()
            ->where('class', $class->class_name)
            ->where('academic_year', $class->academic_year)
            ->where('status', 'active');

        if ($class->section === null || $class->section === '') {
            $studentQuery->where(function ($q) {
                $q->whereNull('section')->orWhere('section', '');
            });
        } else {
            $studentQuery->where('section', $class->section);
        }

        $students = $studentQuery->get();
        $studentIds = $students->pluck('id')->toArray();
        $studentCount = count($studentIds);

        $todayAttendance = Attendance::query()
            ->whereIn('student_id', $studentIds)
            ->whereDate('attendance_date', today())
            ->get();

        $todayPresent = $todayAttendance->whereIn('status', ['present', 'late'])->count();
        $todayPercentage = $studentCount > 0
            ? round(($todayPresent / $studentCount) * 100, 1)
            : 0;

        $totalRecords = Attendance::whereIn('student_id', $studentIds)
            ->where('academic_year', $class->academic_year)
            ->count();
        $presentRecords = Attendance::whereIn('student_id', $studentIds)
            ->where('academic_year', $class->academic_year)
            ->whereIn('status', ['present', 'late'])
            ->count();
        $overallPercentage = $totalRecords > 0
            ? round(($presentRecords / $totalRecords) * 100, 1)
            : 0;

        return [
            'id' => $class->id,
            'class_name' => $class->class_name,
            'section' => $class->section,
            'academic_year' => (string) $class->academic_year,
            'class_teacher' => $class->class_teacher,
            'student_count' => $studentCount,
            'today_attendance_taken' => $todayAttendance->count() > 0,
            'today_percentage' => $todayPercentage,
            'overall_percentage' => $overallPercentage,
            'isAdmin' => $isAdmin,
        ];
    }

    /**
     * ⭐ Ensure that the given year has class records in `classes`.
     *    Same logic as StudentResultController.
     */
    private function ensureClassesForYear(int $year): void
    {
        $exists = SchoolClass::where('academic_year', $year)->exists();
        if ($exists) {
            return;
        }

        $sourceYear = SchoolClass::where('academic_year', '!=', $year)
            ->orderByDesc('academic_year')
            ->value('academic_year');

        if (! $sourceYear) {
            return;
        }

        $sourceClasses = SchoolClass::where('academic_year', $sourceYear)
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        foreach ($sourceClasses as $src) {
            SchoolClass::firstOrCreate(
                [
                    'class_name'    => $src->class_name,
                    'section'       => $src->section,
                    'academic_year' => $year,
                ],
                [
                    'class_teacher' => $src->class_teacher,
                    'subjects'      => $src->subjects ?? [],
                    'status'        => $src->status ?? 'active',
                ]
            );
        }
    }
}