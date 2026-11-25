<?php

namespace App\Http\Controllers;

use App\Models\ResultInput; 
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $query->visibleTo($user, 'results.view');

        // ✅ Class filter
        if ($classNameFilter !== '') {
            $query->where('class_name', $classNameFilter);
        }

        if ($request->filled('group')) {
            $query->where('study_group_id', (int) $request->input('group'));
        }

        if ($request->filled('shift')) {
            $query->where('shift_id', (int) $request->input('shift'));
        }

        if ($request->filled('medium')) {
            $query->where('medium_id', (int) $request->input('medium'));
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
                  ->orWhereHas('currentTeacher', fn ($t) => $t->where('name', 'LIKE', "%{$search}%"));
            });
        }

        // ✅ Pagination — 12 per page
        $paginatedClasses = $query->paginate(12)->withQueryString();

        [$classCounts, $classLocked] = $this->classStats($paginatedClasses->items());

        $classesData = collect($paginatedClasses->items())->map(function ($class) use ($classCounts, $classLocked) {
            $studentCount = (int) ($classCounts[$class->id] ?? 0);
            $isLocked = $classLocked->has($class->id);

            return [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'class_label' => $class->class_label,
                'section_label' => $class->section_label,
                'section' => $class->section,
                'group' => $class->group_name,
                'shift' => $class->shift_name,
                'medium' => $class->medium_name,
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
            'groups' => \App\Models\StudyGroup::active()->ordered()->get(['id', 'name']),
            'shifts' => \App\Models\Shift::active()->ordered()->get(['id', 'name']),
            'mediums' => \App\Models\Medium::active()->ordered()->get(['id', 'name']),
            'filters' => [
                'group' => (string) $request->input('group', ''),
                'shift' => (string) $request->input('shift', ''),
                'medium' => (string) $request->input('medium', ''),
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

        if (! $user->canDo('results.view', $class)) {
            abort(403, 'You are not authorized for this class.');
        }

        // null = whole class (admin / class teacher); array = a subject teacher's own subjects only.
        $viewSubjects = $user->subjectsAllowedFor($class, 'results.view');
        $restricted   = $viewSubjects !== null;
        $enterSubjects = $user->subjectsAllowedFor($class, 'results.enter');
        $canUnlock    = $user->hasAbility('results.unlock');

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

        $classQuery->visibleTo($user, 'results.view');

        $paginatedSidebarClasses = $classQuery->paginate(12)->withQueryString();

        [$classCounts, $classLocked] = $this->classStats($paginatedSidebarClasses->items());

        $sidebarClasses = collect($paginatedSidebarClasses->items())->map(function ($c) use ($classCounts, $classLocked) {
            $studentCount = (int) ($classCounts[$c->id] ?? 0);
            $isLocked = $classLocked->has($c->id);

            return [
                'id' => $c->id,
                'class_name' => $c->class_name,
                'class_label' => $c->class_label,
                'section_label' => $c->section_label,
                'section' => $c->section,
                'group' => $c->group_name,
                'shift' => $c->shift_name,
                'medium' => $c->medium_name,
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
            ->where('class_id', $class->id)
            ->where('status', 'active')
            ->count();

        // ✅ Students query with backend filtering
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

        // ✅ Pagination — 40 per page
        $paginatedStudents = $studentsQuery
            ->orderBy('roll_number')
            ->orderBy('student_name')
            ->paginate(40)
            ->withQueryString();

        $pageStudents = collect($paginatedStudents->items());
        $classStudentIds = $pageStudents->pluck('id')->toArray();

        $subjects = $class->subjects ?? [];
        if ($restricted) {
            $subjects = array_values(array_intersect($subjects, $viewSubjects));
        }

        // Existing results (for page students only)
        $existingResults = collect();
        if (! empty($classStudentIds)) {
            $existingResults = ResultInput::where('class_id', $class->id)
                ->whereIn('subject', $subjects)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->whereIn('student_id', $classStudentIds)
                ->get()
                ->groupBy('student_id');
        }

        $subjectLocks = [];

        $lockRows = ResultInput::with('lockedBy:id,name')
            ->where('class_id', $class->id)
            ->where('exam_type', $examType)
            ->where('academic_year', $class->academic_year)
            ->where('is_locked', true)
            ->get()
            ->keyBy('subject');

        foreach ($subjects as $subject) {
            $lockRow = $lockRows->get($subject);

            $subjectLocks[$subject] = [
                'is_locked' => (bool) $lockRow,
                'locked_at' => $lockRow?->locked_at?->format('d M Y, h:i A'),
                'locked_by' => $lockRow?->lockedBy?->name,
                // may THIS user type marks for the subject right now?
                'can_enter' => ($enterSubjects === null || in_array($subject, $enterSubjects, true))
                    && (! $lockRow || $canUnlock),
            ];
        }

        // Subject-wise highest marks (whole class)
        $subjectHighest = [];
        if (! empty($classStudentIds)) {
            $subjectHighest = ResultInput::where('class_id', $class->id)
                ->whereIn('subject', $subjects)
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
            $totalsFromDb = ResultInput::where('class_id', $class->id)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->selectRaw('student_id, SUM(marks) as total')
                ->groupBy('student_id')
                ->pluck('total', 'student_id')
                ->toArray();
        }

        // Fail count per student
        $failCounts = [];
        if (! empty($classStudentIds)) {
            $failCounts = ResultInput::where('class_id', $class->id)
                ->where('exam_type', $examType)
                ->where('academic_year', $class->academic_year)
                ->where('grade', 'F')
                ->selectRaw('student_id, COUNT(*) as fail_count')
                ->groupBy('student_id')
                ->pluck('fail_count', 'student_id')
                ->toArray();
        }

        // ✅ Ranking (whole class, so the rank is the same on every page)
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

        if ($restricted) {
            $totalsFromDb = [];
            $ranks        = [];
            $failCounts   = [];
        }

        $studentsData = $pageStudents->map(function ($student) use ($existingResults, $totalsFromDb, $ranks, $failCounts, $class, $restricted) {
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

            if ($restricted) {
                // A subject teacher only needs to identify the student and enter their own subject.
                return [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'student_name' => $student->student_name,
                    'student_photo' => $student->student_photo,
                    'roll_number' => $student->roll_number,
                    'class' => $class->class_name,
                    'section' => $class->section,
                    'group' => $class->group_name,
                    'shift' => $class->shift_name,
                    'medium' => $class->medium_name,
                    'academic_year' => (string) $student->academic_year,
                    'marks' => $marks,
                    'subjects' => $subjectData,
                    'total_marks' => null,
                    'rank' => null,
                    'has_fail' => false,
                    'overall_gpa' => '-',
                    'overall_letter' => '-',
                ];
            }

            return [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'student_name' => $student->student_name,
                'student_photo' => $student->student_photo,
                'roll_number' => $student->roll_number,
                'class' => $class->class_name,
                'section' => $class->section,
                'group' => $class->group_name,
                'shift' => $class->shift_name,
                'medium' => $class->medium_name,
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
                'class_label' => $class->class_label,
                'section_label' => $class->section_label,
                'section' => $class->section,
                'group' => $class->group_name,
                'shift' => $class->shift_name,
                'medium' => $class->medium_name,
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
            'restricted' => $restricted,
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
     * Save results.
     *
     * Who may type which subject:
     *   - subject teacher  → only the subjects they teach in this class
     *   - class teacher    → any subject of their class that is not locked
     *                        (so they can enter marks on a subject teacher's behalf)
     *   - admin            → anything, locked subjects included
     * A locked subject can only be changed by someone who may unlock it.
     */
    public function store(Request $request, $classId)
    {
        $class = SchoolClass::findOrFail($classId);
        $user  = Auth::user();

        if (! $user->canDo('results.enter', $class)) {
            abort(403);
        }

        $validated = $request->validate([
            'exam_type' => 'required|string|max:50',
            'results' => 'required|array|max:5000',
            'results.*.student_id' => 'required|integer',
            'results.*.subject' => 'required|string|max:100',
            'results.*.marks' => 'required|numeric|min:0|max:100',
        ]);

        $examType      = $validated['exam_type'];
        $records       = collect($validated['results']);
        $classSubjects = $class->subjects ?? [];

        // Every student must belong to THIS class, every subject must be one of its subjects.
        $studentIds = $records->pluck('student_id')->unique()->values();
        $inClass    = Student::query()->where('class_id', $class->id)->whereIn('id', $studentIds)->pluck('id')->flip();

        if ($inClass->count() !== $studentIds->count()) {
            return back()->with('error', 'Some students do not belong to this class. Nothing was saved.');
        }

        if ($records->pluck('subject')->unique()->diff($classSubjects)->isNotEmpty()) {
            return back()->with('error', 'Some subjects do not belong to this class. Nothing was saved.');
        }

        $allowed    = $user->subjectsAllowedFor($class, 'results.enter');   // null = every subject
        $canUnlock  = $user->hasAbility('results.unlock');
        $lockedNow  = ResultInput::query()
            ->where('class_id', $class->id)
            ->where('exam_type', $examType)
            ->where('academic_year', $class->academic_year)
            ->where('is_locked', true)
            ->distinct()
            ->pluck('subject')
            ->flip();

        $notYours = [];
        $locked   = [];
        $rows     = [];
        $now      = now();

        foreach ($records as $record) {
            $subject = $record['subject'];

            if ($allowed !== null && ! in_array($subject, $allowed, true)) {
                $notYours[$subject] = true;
                continue;
            }

            if ($lockedNow->has($subject) && ! $canUnlock) {
                $locked[$subject] = true;
                continue;
            }

            $grade = $this->calculateGrade($record['marks']);

            $rows[] = [
                'student_id'    => (int) $record['student_id'],
                'class_id'      => $class->id,
                'subject'       => $subject,
                'exam_type'     => $examType,
                'academic_year' => (string) $class->academic_year,
                'marks'         => $record['marks'],
                'total_marks'   => 0,                     // recomputed below from ALL subjects
                'grade'         => $grade['letter'],
                'gpa'           => $grade['gpa'],
                'entered_by'    => $user->id,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        DB::transaction(function () use ($rows, $class, $examType) {
            foreach (array_chunk($rows, 500) as $chunk) {
                ResultInput::upsert(
                    $chunk,
                    ['student_id', 'class_id', 'subject', 'exam_type', 'academic_year'],
                    ['marks', 'grade', 'gpa', 'entered_by', 'updated_at']
                );
            }

            // A student's total is the sum of ALL their subjects — not just the ones saved in this request
            // (several teachers enter different subjects at different times).
            DB::update(
                'UPDATE results r
                 JOIN (SELECT student_id, SUM(marks) AS t FROM results
                        WHERE class_id = ? AND exam_type = ? AND academic_year = ?
                        GROUP BY student_id) x ON x.student_id = r.student_id
                    SET r.total_marks = x.t
                  WHERE r.class_id = ? AND r.exam_type = ? AND r.academic_year = ?',
                [$class->id, $examType, $class->academic_year, $class->id, $examType, $class->academic_year]
            );
        });

        $saved = count($rows);
        $notes = [];

        if ($locked) {
            $notes[] = '🔒 Locked: ' . implode(', ', array_keys($locked)) . ' (only an admin can change them)';
        }
        if ($notYours) {
            $notes[] = '⛔ Not your subject: ' . implode(', ', array_keys($notYours));
        }

        if ($saved === 0 && $notes) {
            return back()->with('error', implode(' — ', $notes));
        }

        $message = "{$saved} marks saved" . ($notes ? '. ' . implode(' ', $notes) : ' successfully!');

        return redirect()
            ->route('results.show', [$class->id, 'exam_type' => $examType])
            ->with('success', $message);
    }

    /**
     * Lock a specific subject
     */
    public function lock(Request $request, $classId)
    {
        $class = SchoolClass::findOrFail($classId);
        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        if (! $user->canDo('results.lock', $class)) {
            abort(403);
        }

        $validated = $request->validate([
            'exam_type' => 'required|string',
            'subject' => 'required|string',
        ]);

        $lockable = $user->subjectsAllowedFor($class, 'results.lock');   // null = any subject of the class

        if (! in_array($validated['subject'], $class->subjects ?? [], true)
            || ($lockable !== null && ! in_array($validated['subject'], $lockable, true))) {
            abort(403, 'You may not lock this subject.');
        }

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

            $q->visibleTo($user, 'results.view');

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

    /**
     * One grouped query each for student counts and locked state, instead of
     * two queries per class in the list.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function classStats(iterable $classes): array
    {
        $ids = collect($classes)->pluck('id');

        $counts = Student::query()
            ->whereIn('class_id', $ids)
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) AS total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $locked = ResultInput::query()
            ->whereIn('class_id', $ids)
            ->where('exam_type', 'final')
            ->where('is_locked', true)
            ->distinct()
            ->pluck('class_id')
            ->flip();

        return [$counts, $locked];
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