<?php

namespace App\Http\Controllers;

use App\Models\ResultInput; 
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ResultInputController extends Controller 
{
    /**
     * Class list page (with year filter + class/section filter + pagination + search)
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        // ✅ String year
        $selectedYear = (string) $request->input('academic_year', now()->year);

        // ✅ Class & Section filter (backend)
        $classNameFilter = (string) $request->input('class_name', '');
        $sectionFilter = (string) $request->input('section', '');

        // ✅ Section sudhu class select thakle kaj korbe
        if ($classNameFilter === '') {
            $sectionFilter = '';
        }

        // ✅ Available years — string[]
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

        // ✅ Class name & section dropdown options (database theke)
        [$classNames, $sections] = $this->getFilterOptions($selectedYear, $isAdmin, $user, $classNameFilter);

        // ✅ Class query — filter by academic_year
        $query = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section');

        if (! $isAdmin) {
            $query->where('class_teacher', $user->name);
        }

        // ✅ Class filter
        if ($classNameFilter !== '') {
            $query->where('class_name', $classNameFilter);
        }

        // ✅ Section filter
        if ($sectionFilter !== '') {
            $query->where('section', $sectionFilter);
        }

        // ✅ Backend search filter (class name / section / teacher)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('class_name', 'LIKE', "%{$search}%")
                  ->orWhere('section', 'LIKE', "%{$search}%")
                  ->orWhere('class_teacher', 'LIKE', "%{$search}%");
            });
        }

        // ✅ Pagination — 12 per page
        $paginatedClasses = $query->paginate(12)->withQueryString();

        $classesData = collect($paginatedClasses->items())->map(function ($class) {
            $studentCount = Student::where('class', $class->class_name)
                ->where('section', $class->section)
                ->where('academic_year', $class->academic_year)
                ->where('status', 'active')
                ->count();

            $isLocked = ResultInput::where('class_id', $class->id)
                ->where('exam_type', 'final')
                ->where('academic_year', $class->academic_year)
                ->where('is_locked', true)
                ->exists();

            return [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'section' => $class->section,
                'academic_year' => (string) $class->academic_year,
                'class_teacher' => $class->class_teacher,
                'subjects' => $class->subjects ?? [],
                'student_count' => $studentCount,
                'is_locked' => $isLocked,
            ];
        });

        // ✅ Rebuild paginator
        $classesPaginated = [
            'data' => $classesData,
            'links' => $paginatedClasses->linkCollection()->toArray(),
            'current_page' => $paginatedClasses->currentPage(),
            'last_page' => $paginatedClasses->lastPage(),
            'from' => $paginatedClasses->firstItem(),
            'to' => $paginatedClasses->lastItem(),
            'total' => $paginatedClasses->total(),
            'per_page' => $paginatedClasses->perPage(),
        ];

        return Inertia::render('ResultInput', [ 
            'classes' => $classesPaginated,
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
            'totalStudents' => 0,
            'subjects' => [],
            'subjectLocks' => (object) [],
            'subjectHighest' => (object) [],
            'examType' => 'final',
            'examTypes' => $this->getExamTypes(),
            'isAdmin' => $isAdmin,
            'schoolInfo' => $this->getSchoolInfo(),
            'flash' => session()->only(['success', 'error']),
            'availableYears' => $availableYears,
            'classNames' => $classNames,
            'sections' => $sections,
            'selectedYear' => $selectedYear,
            'filters' => [
                'academic_year' => $selectedYear,
                'exam_type' => 'final',
                'search' => $request->input('search', ''),
                'roll_number' => '',
                'class_name' => $classNameFilter,
                'section' => $sectionFilter,
            ],
        ]);
    }

    /**
     * Show class with students & marks input
     * ✅ Backend filtering + pagination (40 students per page)
     */
    public function show(Request $request, $classId)
    {
        $class = SchoolClass::findOrFail($classId);
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        if (! $isAdmin && $class->class_teacher !== $user->name) {
            abort(403, 'You are not authorized for this class.');
        }

        $examType = $request->input('exam_type', 'final');
        $search = $request->input('search', '');
        $rollNumber = $request->input('roll_number', '');

        $selectedYear = (string) $class->academic_year;

        // Sidebar classes (same year, same pagination)
        $classQuery = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section');

        if (! $isAdmin) {
            $classQuery->where('class_teacher', $user->name);
        }

        $paginatedSidebarClasses = $classQuery->paginate(12)->withQueryString();

        $sidebarClasses = collect($paginatedSidebarClasses->items())->map(function ($c) {
            $studentCount = Student::where('class', $c->class_name)
                ->where('section', $c->section)
                ->where('academic_year', $c->academic_year)
                ->where('status', 'active')
                ->count();

            $isLocked = ResultInput::where('class_id', $c->id)
                ->where('exam_type', 'final')
                ->where('academic_year', $c->academic_year)
                ->where('is_locked', true)
                ->exists();

            return [
                'id' => $c->id,
                'class_name' => $c->class_name,
                'section' => $c->section,
                'academic_year' => (string) $c->academic_year,
                'class_teacher' => $c->class_teacher,
                'subjects' => $c->subjects ?? [],
                'student_count' => $studentCount,
                'is_locked' => $isLocked,
            ];
        });

        $classesPaginated = [
            'data' => $sidebarClasses,
            'links' => $paginatedSidebarClasses->linkCollection()->toArray(),
            'current_page' => $paginatedSidebarClasses->currentPage(),
            'last_page' => $paginatedSidebarClasses->lastPage(),
            'from' => $paginatedSidebarClasses->firstItem(),
            'to' => $paginatedSidebarClasses->lastItem(),
            'total' => $paginatedSidebarClasses->total(),
            'per_page' => $paginatedSidebarClasses->perPage(),
        ];

        // Unfiltered total
        $totalStudents = Student::query()
            ->where('class', $class->class_name)
            ->where('section', $class->section)
            ->where('academic_year', $class->academic_year)
            ->where('status', 'active')
            ->count();

        // ✅ Students query with backend filtering
        $studentsQuery = Student::query()
            ->where('class', $class->class_name)
            ->where('section', $class->section)
            ->where('academic_year', $class->academic_year)
            ->where('status', 'active');

        if (! empty($search)) {
            $studentsQuery->where(function ($q) use ($search) {
                $q->where('student_name', 'LIKE', "%{$search}%")
                  ->orWhere('student_id', 'LIKE', "%{$search}%");
            });
        }

        if (! empty($rollNumber)) {
            $studentsQuery->where('roll_number', 'LIKE', "%{$rollNumber}%");
        }

        // ✅ Pagination — 40 per page
        $paginatedStudents = $studentsQuery
            ->orderBy('roll_number')
            ->orderBy('student_name')
            ->paginate(40)
            ->withQueryString();

        $pageStudents = collect($paginatedStudents->items());
        $classStudentIds = $pageStudents->pluck('id')->toArray();

        // Existing results (for page students only)
        $existingResults = collect();
        if (! empty($classStudentIds)) {
            $existingResults = ResultInput::where('class_id', $class->id)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->whereIn('student_id', $classStudentIds)
                ->get()
                ->groupBy('student_id');
        }

        $subjects = $class->subjects ?? [];
        $subjectLocks = [];

        foreach ($subjects as $subject) {
            $lockRow = ResultInput::where('class_id', $class->id)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->where('subject', $subject)
                ->where('is_locked', true)
                ->first();

            $subjectLocks[$subject] = [
                'is_locked' => (bool) $lockRow,
                'locked_at' => $lockRow?->locked_at?->format('d M Y, h:i A'),
                'locked_by' => $lockRow?->lockedBy?->name,
            ];
        }

        // Subject-wise highest marks (for page students only)
        $subjectHighest = [];
        if (! empty($classStudentIds)) {
            $subjectHighest = ResultInput::whereIn('student_id', $classStudentIds)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->selectRaw('subject, MAX(marks) as highest')
                ->groupBy('subject')
                ->pluck('highest', 'subject')
                ->toArray();
        }

        // Total marks per student
        $totalsFromDb = [];
        if (! empty($classStudentIds)) {
            $totalsFromDb = ResultInput::whereIn('student_id', $classStudentIds)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->selectRaw('student_id, MAX(total_marks) as total')
                ->groupBy('student_id')
                ->pluck('total', 'student_id')
                ->toArray();
        }

        // Fail count per student
        $failCounts = [];
        if (! empty($classStudentIds)) {
            $failCounts = ResultInput::whereIn('student_id', $classStudentIds)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->where('grade', 'F')
                ->selectRaw('student_id, COUNT(*) as fail_count')
                ->groupBy('student_id')
                ->pluck('fail_count', 'student_id')
                ->toArray();
        }

        // ✅ Ranking (page students only)
        $rankable = [];
        foreach ($totalsFromDb as $sid => $total) {
            $rankable[] = [
                'student_id' => $sid,
                'total' => (int) $total,
                'fails' => (int) ($failCounts[$sid] ?? 0),
            ];
        }

        usort($rankable, function ($a, $b) {
            if ($a['fails'] !== $b['fails']) {
                return $a['fails'] <=> $b['fails'];
            }
            return $b['total'] <=> $a['total'];
        });

        $ranks = [];
        $position = 1;
        $densePosition = 1;
        $prevFails = null;
        $prevTotal = null;

        foreach ($rankable as $entry) {
            if ($prevFails !== null && ($entry['fails'] !== $prevFails || $entry['total'] !== $prevTotal)) {
                $densePosition = $position;
            }
            $ranks[$entry['student_id']] = $densePosition;
            $prevFails = $entry['fails'];
            $prevTotal = $entry['total'];
            $position++;
        }

        $studentsData = $pageStudents->map(function ($student) use ($existingResults, $totalsFromDb, $ranks, $failCounts) {
            $studentResults = $existingResults->get($student->id, collect());

            $marks = [];
            $subjectData = [];
            $gpaSum = 0;
            $gpaCount = 0;

            foreach ($studentResults as $result) {
                $marks[$result->subject] = $result->marks;
                $subjectData[] = [
                    'subject' => $result->subject,
                    'marks' => $result->marks,
                    'grade' => $result->grade,
                    'gpa' => (float) $result->gpa,
                ];
                $gpaSum += (float) $result->gpa;
                $gpaCount++;
            }

            $hasFail = (int) ($failCounts[$student->id] ?? 0) > 0;
            $overallGpa = $gpaCount > 0 ? round($gpaSum / $gpaCount, 2) : 0.00;
            $overallLetter = $this->gpaToLetter($overallGpa, $hasFail);

            return [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'student_name' => $student->student_name,
                'student_photo' => $student->student_photo,
                'roll_number' => $student->roll_number,
                'class' => $student->class,
                'section' => $student->section,
                'date_of_birth' => $student->date_of_birth,
                'gender' => $student->gender,
                'blood_group' => $student->blood_group,
                'father_name' => $student->father_name,
                'mother_name' => $student->mother_name,
                'academic_year' => (string) $student->academic_year,
                'admission_date' => $student->admission_date,
                'marks' => $marks,
                'subjects' => $subjectData,
                'total_marks' => $totalsFromDb[$student->id] ?? 0,
                'rank' => $ranks[$student->id] ?? null,
                'has_fail' => $hasFail,
                'overall_gpa' => number_format($overallGpa, 2, '.', ''),
                'overall_letter' => $overallLetter,
            ];
        });

        // ✅ Students paginator
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

        // ✅ Available years — string[]
        $availableYears = SchoolClass::query()
            ->where('status', 'active')
            ->select('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->map(fn ($y) => (string) $y)
            ->toArray();

        return Inertia::render('ResultInput', [ 
            'classes' => $classesPaginated,
            'classInfo' => [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'section' => $class->section,
                'academic_year' => (string) $class->academic_year,
                'class_teacher' => $class->class_teacher,
            ],
            'students' => $studentsPaginated,
            'totalStudents' => $totalStudents,
            'subjects' => $subjects,
            'subjectLocks' => $subjectLocks,
            'subjectHighest' => $subjectHighest,
            'examType' => $examType,
            'examTypes' => $this->getExamTypes(),
            'isAdmin' => $isAdmin,
            'schoolInfo' => $this->getSchoolInfo(),
            'flash' => session()->only(['success', 'error']),
            'availableYears' => $availableYears,
            'classNames' => [],
            'sections' => [],
            'selectedYear' => $selectedYear,
            'filters' => [
                'academic_year' => $selectedYear,
                'exam_type' => $examType,
                'search' => $search,
                'roll_number' => $rollNumber,
                'class_name' => '',
                'section' => '',
            ],
        ]);
    }

    /**
     * Save results
     */
    public function store(Request $request, $classId)
    {
        $class = SchoolClass::findOrFail($classId);
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        if (! $isAdmin && $class->class_teacher !== $user->name) {
            abort(403);
        }

        $validated = $request->validate([
            'exam_type' => 'required|string',
            'results' => 'required|array',
            'results.*.student_id' => 'required|integer|exists:students,id',
            'results.*.subject' => 'required|string',
            'results.*.marks' => 'required|numeric|min:0|max:100',
        ]);

        $savedCount = 0;
        $skippedSubjects = [];

        $marksByStudent = [];
        foreach ($validated['results'] as $record) {
            $marksByStudent[$record['student_id']][$record['subject']] = $record['marks'];
        }

        $totalsByStudent = [];
        foreach ($marksByStudent as $studentId => $subjects) {
            $totalsByStudent[$studentId] = array_sum($subjects);
        }

        foreach ($validated['results'] as $record) {
            $isLocked = ResultInput::where('class_id', $class->id)
                ->where('exam_type', $validated['exam_type'])
                ->where('academic_year', $class->academic_year)
                ->where('subject', $record['subject'])
                ->where('is_locked', true)
                ->exists();

            if ($isLocked && ! $isAdmin) {
                if (! in_array($record['subject'], $skippedSubjects)) {
                    $skippedSubjects[] = $record['subject'];
                }
                continue;
            }

            $gradeInfo = $this->calculateGrade($record['marks']);

            ResultInput::updateOrCreate(
                [
                    'student_id' => $record['student_id'],
                    'class_id' => $class->id,
                    'subject' => $record['subject'],
                    'exam_type' => $validated['exam_type'],
                    'academic_year' => $class->academic_year,
                ],
                [
                    'marks' => $record['marks'],
                    'total_marks' => $totalsByStudent[$record['student_id']] ?? 0,
                    'grade' => $gradeInfo['letter'],
                    'gpa' => $gradeInfo['gpa'],
                    'entered_by' => $user->id,
                ]
            );

            $savedCount++;
        }

        if (! empty($skippedSubjects)) {
            $subjectList = implode(', ', $skippedSubjects);

            if ($savedCount > 0) {
                return redirect()
                    ->route('results.show', [$class->id, 'exam_type' => $validated['exam_type']])
                    ->with('success', "{$savedCount} marks saved. 🔒 Locked: {$subjectList}");
            }

            return back()->with('error', "🔒 Locked subjects: {$subjectList}. Only admin can modify them.");
        }

        return redirect()
            ->route('results.show', [$class->id, 'exam_type' => $validated['exam_type']])
            ->with('success', "{$savedCount} marks saved successfully!");
    }

    /**
     * Lock a specific subject
     */
    public function lock(Request $request, $classId)
    {
        $class = SchoolClass::findOrFail($classId);
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        if (! $isAdmin && $class->class_teacher !== $user->name) {
            abort(403);
        }

        $validated = $request->validate([
            'exam_type' => 'required|string',
            'subject' => 'required|string',
        ]);

        $hasResults = ResultInput::where('class_id', $class->id)
            ->where('exam_type', $validated['exam_type'])
            ->where('academic_year', $class->academic_year)
            ->where('subject', $validated['subject'])
            ->exists();

        if (! $hasResults) {
            return back()->with('error', "No marks found for {$validated['subject']}. Please save first.");
        }

        ResultInput::where('class_id', $class->id)
            ->where('exam_type', $validated['exam_type'])
            ->where('academic_year', $class->academic_year)
            ->where('subject', $validated['subject'])
            ->update([
                'is_locked' => true,
                'locked_at' => now(),
                'locked_by' => $user->id,
            ]);

        return redirect()
            ->route('results.show', [$class->id, 'exam_type' => $validated['exam_type']])
            ->with('success', "🔒 {$validated['subject']} locked successfully!");
    }

    /**
     * Unlock a specific subject (Admin only)
     */
    public function unlock(Request $request, $classId)
    {
        $user = Auth::user();

        if ($user->role !== 'admin') {
            return back()->with('error', '❌ Only admin can unlock results.');
        }

        $class = SchoolClass::findOrFail($classId);

        $validated = $request->validate([
            'exam_type' => 'required|string',
            'subject' => 'required|string',
        ]);

        ResultInput::where('class_id', $class->id)
            ->where('exam_type', $validated['exam_type'])
            ->where('academic_year', $class->academic_year)
            ->where('subject', $validated['subject'])
            ->update([
                'is_locked' => false,
                'locked_at' => null,
                'locked_by' => null,
            ]);

        return redirect()
            ->route('results.show', [$class->id, 'exam_type' => $validated['exam_type']])
            ->with('success', "🔓 {$validated['subject']} unlocked successfully!");
    }

    /**
     * ✅ Class name & section dropdown options (school_classes table theke)
     * - Class names: selected year er sob class (teacher hole shudhu nijer class)
     * - Sections: shudhu selected class er section
     */
    private function getFilterOptions(string $year, bool $isAdmin, $user, string $className = ''): array
    {
        $baseQuery = function () use ($year, $isAdmin, $user) {
            $q = SchoolClass::query()
                ->where('status', 'active')
                ->where('academic_year', $year);

            if (! $isAdmin) {
                $q->where('class_teacher', $user->name);
            }

            return $q;
        };

        $classNames = $baseQuery()
            ->select('class_name')
            ->distinct()
            ->orderBy('class_name')
            ->pluck('class_name')
            ->values()
            ->toArray();

        $sections = [];
        if ($className !== '') {
            $sections = $baseQuery()
                ->where('class_name', $className)
                ->whereNotNull('section')
                ->where('section', '!=', '')
                ->select('section')
                ->distinct()
                ->orderBy('section')
                ->pluck('section')
                ->values()
                ->toArray();
        }

        return [$classNames, $sections];
    }

    private function calculateGrade($marks): array
    {
        if ($marks >= 80) return ['letter' => 'A+', 'gpa' => 5.00];
        if ($marks >= 70) return ['letter' => 'A',  'gpa' => 4.00];
        if ($marks >= 60) return ['letter' => 'A-', 'gpa' => 3.50];
        if ($marks >= 50) return ['letter' => 'B',  'gpa' => 3.00];
        if ($marks >= 40) return ['letter' => 'C',  'gpa' => 2.00];
        if ($marks >= 33) return ['letter' => 'D',  'gpa' => 1.00];
        return ['letter' => 'F', 'gpa' => 0.00];
    }

    private function gpaToLetter(float $gpa, bool $hasFail = false): string
    {
        if ($hasFail) return 'F';
        if ($gpa >= 5.00) return 'A+';
        if ($gpa >= 4.00) return 'A';
        if ($gpa >= 3.50) return 'A-';
        if ($gpa >= 3.00) return 'B';
        if ($gpa >= 2.00) return 'C';
        if ($gpa >= 1.00) return 'D';
        return 'F';
    }

    private function getExamTypes(): array
    {
        return [
            ['value' => 'first_terminal', 'label' => 'First Terminal Examination'],
            ['value' => 'second_terminal', 'label' => 'Second Terminal Examination'],
            ['value' => 'final', 'label' => 'Final Examination'],
            ['value' => 'model_test', 'label' => 'Model Test'],
        ];
    }

    private function getSchoolInfo(): array
    {
        return [
            'name' => config('app.school_name', 'ChildHome Academy'),
            'address' => config('app.school_address', '123 Education Road, City'),
            'phone' => config('app.school_phone', '+880 1234-567890'),
        ];
    }
}