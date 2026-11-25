<?php

namespace App\Http\Controllers;

use App\Jobs\ShiftStudentsToNextYear;
use App\Models\ResultInput; // ✅ এখানে আপডেট করা হলো
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class StudentResultController extends Controller
{
    /**
     * Step 1: Class list page (with academic year filter)
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        $selectedYear = $request->input('academic_year', now()->year);

        // 🆕 Class / Section filters
        $selectedClassName = (string) $request->input('class_name', '');
        $selectedSection   = (string) $request->input('section', '');

        // ⭐ Ensure classes exist for this year (auto-copy from most recent year)
        SchoolClass::ensureForYear((int) $selectedYear);

        // Available years from classes table
        $availableYears = SchoolClass::query()
            ->select('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->toArray();

        if (empty($availableYears)) {
            $availableYears = [now()->year];
        }

        // Class query — filter by academic_year
        $classQuery = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section');

        $classQuery->visibleTo($user, 'results.compile');

        // 🆕 Class filter (backend)
        if ($selectedClassName !== '') {
            $classQuery->where('class_name', $selectedClassName);
        }

        if ($request->filled('group')) {
            $classQuery->where('study_group_id', (int) $request->input('group'));
        }

        if ($request->filled('shift')) {
            $classQuery->where('shift_id', (int) $request->input('shift'));
        }

        if ($request->filled('medium')) {
            $classQuery->where('medium_id', (int) $request->input('medium'));
        }

        // 🆕 Section filter (backend) — '__none__' = classes without section
        if ($selectedSection !== '') {
            if ($selectedSection === '__none__') {
                $classQuery->where(function ($q) {
                    $q->whereNull('section')->orWhere('section', '');
                });
            } else {
                $classQuery->where('section', $selectedSection);
            }
        }

        // 🆕 Dropdown options for Class / Section filters
        [$filterClassNames, $filterSections, $filterHasNoSection] = $this->buildClassFilterOptions(
            $selectedYear,
            $selectedClassName,
            $user->classScope('results.compile')
        );

        $classRows = $classQuery->get();
        $studentCounts = Student::query()
            ->whereIn('class_id', $classRows->pluck('id'))
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) AS total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $classes = $classRows->map(function ($class) use ($studentCounts) {
            $studentCount = (int) ($studentCounts[$class->id] ?? 0);

            return [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'class_label' => $class->class_label,
                'section_label' => $class->section_label,
                'section' => $class->section,
                'group' => $class->group_name,
                'shift' => $class->shift_name,
                'medium' => $class->medium_name,
                'academic_year' => $class->academic_year,
                'class_teacher' => $class->class_teacher,
                'subjects' => $class->subjects ?? [],
                'student_count' => $studentCount,
            ];
        });

        return Inertia::render('StudentResults', [
            'classes' => $classes,
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
            'examTypes' => $this->getExamTypes(),
            'groups' => \App\Models\StudyGroup::active()->ordered()->get(['id', 'name']),
            'shifts' => \App\Models\Shift::active()->ordered()->get(['id', 'name']),
            'mediums' => \App\Models\Medium::active()->ordered()->get(['id', 'name']),
            'filters' => [
                'group' => (string) $request->input('group', ''),
                'shift' => (string) $request->input('shift', ''),
                'medium' => (string) $request->input('medium', ''),
                'academic_year' => $selectedYear,
                'exam_type' => 'final',
                'search' => '',
                'roll_number' => '',
                'class_name' => $selectedClassName, // 🆕
                'section' => $selectedSection,      // 🆕
            ],
            'availableYears' => $availableYears,
            'filterClassNames' => $filterClassNames,       // 🆕
            'filterSections' => $filterSections,           // 🆕
            'filterHasNoSection' => $filterHasNoSection,   // 🆕
            'isAdmin' => $isAdmin,
            'schoolInfo' => $this->getSchoolInfo(),
            'flash' => session()->only(['success', 'error']),
            'classNames' => $this->getClassNames(),
            'classSections' => $this->getClassSections(),
        ]);
    }

    /**
     * Step 2: Show students of a specific class with BACKEND filtering + PAGINATION
     */
    public function show(Request $request, $classId)
    {
        $class = SchoolClass::findOrFail($classId);

        SchoolClass::ensureForYear((int) $class->academic_year);

        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        if (! $user->canDo('results.compile', $class)) {
            abort(403, 'You are not authorized for this class.');
        }

        $examType = $request->input('exam_type', 'final');
        $search = $request->input('search', '');
        $rollNumber = $request->input('roll_number', '');

        $selectedYear = $class->academic_year;

        $classQuery = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section');

        $classQuery->visibleTo($user, 'results.compile');

        $classRows = $classQuery->get();
        $studentCounts = Student::query()
            ->whereIn('class_id', $classRows->pluck('id'))
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) AS total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $classes = $classRows->map(function ($c) use ($studentCounts) {
            $studentCount = (int) ($studentCounts[$c->id] ?? 0);

            return [
                'id' => $c->id,
                'class_name' => $c->class_name,
                'class_label' => $c->class_label,
                'section_label' => $c->section_label,
                'section' => $c->section,
                'group' => $c->group_name,
                'shift' => $c->shift_name,
                'medium' => $c->medium_name,
                'academic_year' => $c->academic_year,
                'class_teacher' => $c->class_teacher,
                'subjects' => $c->subjects ?? [],
                'student_count' => $studentCount,
            ];
        });

        // Unfiltered total
        $totalStudentsQuery = Student::query()
            ->where('class_id', $class->id)
            ->where('status', 'active');

        $totalStudents = $totalStudentsQuery->count();

        // Students query
        $studentsQuery = Student::query()
            ->where('class_id', $class->id)
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

        $students = $studentsQuery
            ->orderBy('roll_number')
            ->orderBy('student_name')
            ->paginate(12)
            ->withQueryString();

        $currentPageStudents = collect($students->items());
        $studentIds = $currentPageStudents->pluck('id')->toArray();

        $classSubjects = $class->subjects ?? [];

        $subjectHighest = [];
        if (! empty($studentIds)) {
            $subjectHighest = ResultInput::where('class_id', $class->id) // whole class, not just this page
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->selectRaw('subject, MAX(marks) as highest')
                ->groupBy('subject')
                ->pluck('highest', 'subject')
                ->toArray();
        }

        $allResults = collect();
        if (! empty($studentIds)) {
            $allResults = ResultInput::whereIn('student_id', $studentIds) // ✅ এখানে আপডেট
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->get()
                ->groupBy('student_id');
        }

        $totalsFromDb = [];
        if (! empty($studentIds)) {
            $totalsFromDb = ResultInput::where('class_id', $class->id) // whole class, not just this page
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->selectRaw('student_id, SUM(marks) as total')
                ->groupBy('student_id')
                ->pluck('total', 'student_id')
                ->toArray();
        }

        $failCounts = [];
        if (! empty($studentIds)) {
            $failCounts = ResultInput::where('class_id', $class->id) // whole class, not just this page
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->where('grade', 'F')
                ->selectRaw('student_id, COUNT(*) as fail_count')
                ->groupBy('student_id')
                ->pluck('fail_count', 'student_id')
                ->toArray();
        }

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

        // One query for all future-year copies of this page's students
        $futureByStudentId = Student::query()
            ->with('schoolClass:id,class_name,section')
            ->where('academic_year', '>', $class->academic_year)
            ->whereIn('student_id', $currentPageStudents->pluck('student_id')->unique()->values())
            ->orderByDesc('academic_year')
            ->get(['id', 'student_id', 'academic_year', 'class_id'])
            ->groupBy('student_id');

        // Build students data with results
        $studentsData = $currentPageStudents->map(function ($student) use ($allResults, $classSubjects, $subjectHighest, $totalsFromDb, $ranks, $futureByStudentId, $class) {
            $studentResults = $allResults->get($student->id, collect())->keyBy('subject');

            $subjectData = [];
            $totalGpa = 0;
            $count = 0;
            $hasFail = false;

            foreach ($classSubjects as $subject) {
                $result = $studentResults->get($subject);

                if ($result) {
                    $marks = (float) $result->marks;
                    $grade = $result->grade;
                    $gpa = (float) $result->gpa;

                    if ($grade === 'F') $hasFail = true;

                    $totalGpa += $gpa;
                    $count++;

                    $subjectData[] = [
                        'subject' => $subject,
                        'marks' => $marks,
                        'grade' => $grade,
                        'gpa' => $gpa,
                        'highest' => isset($subjectHighest[$subject])
                            ? (float) $subjectHighest[$subject]
                            : $marks,
                    ];
                } else {
                    $subjectData[] = [
                        'subject' => $subject,
                        'marks' => null,
                        'grade' => '-',
                        'gpa' => null,
                        'highest' => isset($subjectHighest[$subject])
                            ? (float) $subjectHighest[$subject]
                            : null,
                    ];
                }
            }

            if ($hasFail) {
                $overallGpa = '0.00';
                $overallLetter = 'F';
            } elseif ($count > 0) {
                $overallGpa = number_format($totalGpa / $count, 2, '.', '');
                $overallLetter = $this->getOverallLetter((float) $overallGpa);
            } else {
                $overallGpa = '-';
                $overallLetter = '-';
            }

            // ⭐ Already shifted to a future year? (looked up once for the whole page)
            $nextYearShifted = $futureByStudentId->get($student->student_id)?->first();

            return [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'student_name' => $student->student_name,
                'student_photo' => $student->student_photo,
                'class' => $class->class_name,
                'section' => $class->section,
                'group' => $class->group_name,
                'shift' => $class->shift_name,
                'medium' => $class->medium_name,
                'roll_number' => $student->roll_number,
                'date_of_birth' => $student->date_of_birth,
                'father_name' => $student->father_name,
                'mother_name' => $student->mother_name,
                'academic_year' => $student->academic_year,
                'subjects' => $subjectData,
                'overall_gpa' => $overallGpa,
                'overall_letter' => $overallLetter,
                'has_fail' => $hasFail,
                'total_marks' => $totalsFromDb[$student->id] ?? 0,
                'rank' => $ranks[$student->id] ?? null,

                // ⭐ Shift status flags
                'is_shifted'         => $nextYearShifted ? true : false,
                'shifted_record_id'  => $nextYearShifted?->id,
                'shifted_to_year'    => $nextYearShifted?->academic_year,
                'shifted_to_class'   => $nextYearShifted?->schoolClass?->class_name,
                'shifted_to_section' => $nextYearShifted?->schoolClass?->section,
            ];
        });

        $studentsPaginated = [
            'data' => $studentsData,
            'links' => $students->linkCollection()->toArray(),
            'current_page' => $students->currentPage(),
            'last_page' => $students->lastPage(),
            'from' => $students->firstItem(),
            'to' => $students->lastItem(),
            'total' => $students->total(),
            'per_page' => $students->perPage(),
        ];

        return Inertia::render('StudentResults', [
            'classes' => $classes,
            'classInfo' => [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'class_label' => $class->class_label,
                'section_label' => $class->section_label,
                'section' => $class->section,
                'group' => $class->group_name,
                'shift' => $class->shift_name,
                'medium' => $class->medium_name,
                'academic_year' => $class->academic_year,
                'class_teacher' => $class->class_teacher,
            ],
            'students' => $studentsPaginated,
            'totalStudents' => $totalStudents,
            'examTypes' => $this->getExamTypes(),
            'filters' => [
                'academic_year' => $selectedYear,
                'exam_type' => $examType,
                'search' => $search,
                'roll_number' => $rollNumber,
            ],
            'availableYears' => SchoolClass::query()
                ->select('academic_year')
                ->distinct()
                ->orderByDesc('academic_year')
                ->pluck('academic_year')
                ->toArray(),
            'isAdmin' => $isAdmin,
            'schoolInfo' => $this->getSchoolInfo(),
            'flash' => session()->only(['success', 'error']),
            'classNames' => $this->getClassNames(),
            'classSections' => $this->getClassSections(),
        ]);
    }

    /**
     * ⭐ Shift passed students to the next class for a new academic year.
     */
    public function shift(Request $request)
    {
        $validated = $request->validate([
            'student_ids'      => 'required|array|min:1|max:5000',
            'student_ids.*'    => 'integer|exists:students,id',
            'target_class'     => 'required|string|max:50',
            'target_section'   => 'nullable|string|max:50',
            'from_year'        => 'required|integer|min:2000|max:2100',
            'to_year'          => 'required|integer|min:2000|max:2100',
        ]);

        set_time_limit(0);
        ignore_user_abort(true);

        // Make sure the target year has its class rows BEFORE resolving the target.
        SchoolClass::ensureForYear((int) $validated['to_year']);

        // Students stay in their own shift and medium when they move up; when the same class and
        // section exists in several of them, that is what tells them apart.
        $sources  = SchoolClass::whereIn('id', \App\Models\Student::whereIn('id', $validated['student_ids'])->pluck('class_id')->unique())->get();
        $shiftId  = $sources->pluck('shift_id')->unique()->count() === 1 ? $sources->first()->shift_id : null;
        $mediumId = $sources->pluck('medium_id')->unique()->count() === 1 ? $sources->first()->medium_id : null;

        $targetClass = SchoolClass::resolveActive($validated['target_class'], $validated['target_section'] ?? null, (int) $validated['to_year'], null, $shiftId, $mediumId)
            ?? SchoolClass::resolveActive($validated['target_class'], $validated['target_section'] ?? null, (int) $validated['to_year']);

        if (! $targetClass) {
            return back()->with('error', 'Target class/section does not exist for ' . $validated['to_year'] . '.');
        }

        (new ShiftStudentsToNextYear(
            $validated['student_ids'],
            $targetClass->id,
            (int) $validated['from_year'],
            (int) $validated['to_year'],
            auth()->id(),
        ))->handle();

        $result = Cache::get('shift_result_' . auth()->id(), []);

        return back()->with(
            'success',
            '✅ ' . ($result['inserted'] ?? 0) . ' student(s) shifted to ' .
            $validated['target_class'] .
            (!empty($validated['target_section']) ? ' — ' . $validated['target_section'] : '') .
            ' for ' . $validated['to_year'] .
            (!empty($result['skipped']) ? ' (' . $result['skipped'] . ' already existed).' : '.')
        );
    }

    /**
     * ⭐ Result of the last shift run for the current user (polled by the UI).
     */
    public function shiftStatus()
    {
        return response()->json(Cache::get('shift_result_' . auth()->id(), ['done' => true]));
    }

    /**
     * ⭐ Reset (delete) a shifted student record so the source student can be shifted again.
     */
    public function resetShift(Request $request, $recordId)
    {
        $record = Student::findOrFail($recordId);

        // Safety: only allow deleting shifted records (not the original source year)
        // Simple rule: the record must belong to a FUTURE year compared to some source record
        // For now, just delete the record.
        $record->delete();

        return back()->with('success', '✅ Shift record deleted. Student can be shifted again.');
    }


    /**
     * 🆕 Build Class / Section dropdown options for the class-list filter.
     *    Scoped by selected year (and teacher for non-admin).
     *    Sections are narrowed by the selected class (if any).
     *
     * @return array [classNames[], sections[], hasNoSection(bool)]
     */
    private function buildClassFilterOptions($year, string $className = '', ?array $classIds = null): array
    {
        $base = SchoolClass::query()
            ->where('status', 'active')
            ->where('academic_year', $year);

        if ($classIds !== null) {
            $base->whereIn('id', $classIds);
        }

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
            ->where(function ($q) {
                $q->whereNull('section')->orWhere('section', '');
            })
            ->exists();

        return [$classNames, $sections, $hasNoSection];
    }

    /**
     * Exam types list
     */
    private function getExamTypes(): array
    {
        return [
            ['value' => 'first_terminal', 'label' => 'First Terminal Examination'],
            ['value' => 'second_terminal', 'label' => 'Second Terminal Examination'],
            ['value' => 'final', 'label' => 'Final Examination'],
            ['value' => 'model_test', 'label' => 'Model Test'],
        ];
    }

    /**
     * Overall letter from GPA
     */
    private function getOverallLetter(float $gpa): string
    {
        if ($gpa >= 5.00) return 'A+';
        if ($gpa >= 4.00) return 'A';
        if ($gpa >= 3.50) return 'A-';
        if ($gpa >= 3.00) return 'B';
        if ($gpa >= 2.00) return 'C';
        if ($gpa >= 1.00) return 'D';
        return 'F';
    }

    /**
     * ⭐ Distinct class names (for shift dropdown)
     */
    private function getClassNames(): array
    {
        return SchoolClass::query()
            ->where('status', 'active')
            ->whereNotNull('class_name')
            ->where('class_name', '!=', '')
            ->distinct()
            ->orderBy('class_name')
            ->pluck('class_name')
            ->values()
            ->toArray();
    }

    /**
     * ⭐ Sections grouped by class name (for shift dropdown).
     *    Reads from `classes` (SchoolClass) table — not from students —
     *    so target-class dropdown shows only that class's own sections.
     *    Returns: ["Class 1" => ["A", "Megna"], "Class 6" => [], ...]
     */
    private function getClassSections(): array
    {
        $rows = SchoolClass::query()
            ->select('class_name', 'section')
            ->where('status', 'active')
            ->whereNotNull('class_name')
            ->where('class_name', '!=', '')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $className = $row->class_name;
            $section   = $row->section;

            if (! array_key_exists($className, $map)) {
                $map[$className] = [];
            }

            if ($section === null || $section === '') {
                continue;
            }

            if (! in_array($section, $map[$className], true)) {
                $map[$className][] = $section;
            }
        }

        foreach ($map as $className => $sections) {
            sort($sections);
            $map[$className] = array_values(array_unique($sections));
        }

        return $map;
    }

    /**
     * School info for print
     */
    private function getSchoolInfo(): array
    {
        return [
            'name' => config('app.school_name', 'ChildHome Academy'),
            'address' => config('app.school_address', '123 Education Road, City'),
            'phone' => config('app.school_phone', '+880 1234-567890'),
        ];
    }
}