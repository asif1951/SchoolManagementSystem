<?php

namespace App\Http\Controllers;

use App\Models\ResultInput; // ✅ এখানে আপডেট করা হলো
use App\Models\SchoolClass;
use App\Models\WebsiteHero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class PublicResultController extends Controller
{
    /**
     * Fixed full marks for every subject.
     */
    private const SUBJECT_FULL_MARKS = 100;

    public function index()
    {
        $hero = WebsiteHero::first();

        $classes = SchoolClass::where('status', 'active')
            ->orderBy('class_name', 'asc')
            ->get(['id', 'class_name', 'section', 'academic_year'])
            ->map(function ($c) {
                return [
                    'id'            => $c->id,
                    'class_name'    => $c->class_name,
                    'section'       => $c->section,
                    'academic_year' => $c->academic_year,
                ];
            });

        $years = SchoolClass::where('status', 'active')
            ->whereNotNull('academic_year')
            ->distinct()
            ->orderBy('academic_year', 'desc')
            ->pluck('academic_year')
            ->filter()
            ->values();

        $currentYear = (int) date('Y');
        if (!$years->contains($currentYear)) {
            $years->prepend($currentYear);
        }

        return Inertia::render('PublicResult', [
            'hero'        => $hero,
            'classes'     => $classes,
            'years'       => $years->values(),
            'currentYear' => $currentYear,
        ]);
    }

    public function fetch(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|string|max:100',
            'class_id'   => 'required|integer|exists:classes,id',
            'year'       => 'required|string|max:10',
            'exam'       => 'required|string|max:100',
            'section'    => 'nullable|string|max:50',
        ]);

        $rollInput    = trim($validated['student_id']);
        $classId      = $validated['class_id'];
        $year         = (string) $validated['year'];
        $exam         = $validated['exam'];
        $sectionInput = $validated['section'] ?? null;

        // ============================================================
        // STEP 1: Get the selected class
        // ============================================================
        $selectedClass     = SchoolClass::find($classId);
        $selectedClassName = $selectedClass?->class_name;

        $selectedSection = ($sectionInput && $sectionInput !== 'all')
            ? $sectionInput
            : null;

        // ============================================================
        // STEP 2: Check if the selected class has ANY sections
        // ============================================================
        $classHasSections = false;
        if ($selectedClassName) {
            $classHasSections = SchoolClass::where('class_name', $selectedClassName)
                ->whereNotNull('section')
                ->where('section', '!=', '')
                ->exists();
        }

        if ($classHasSections && !$selectedSection) {
            return response()->json([
                'success' => false,
                'message' => 'This class has multiple sections. Please select a section to view the result.',
            ], 422);
        }

        // ============================================================
        // STEP 3: Find matching students
        // ============================================================
        $studentRecords = collect();

        if (Schema::hasTable('students')) {
            $q = DB::table('students')
                ->where(function ($sub) use ($rollInput) {
                    $sub->where('roll_number', $rollInput)
                        ->orWhere('student_id', $rollInput)
                        ->orWhere('id', $rollInput);
                });

            if ($selectedClassName && Schema::hasColumn('students', 'class')) {
                $q->where('class', $selectedClassName);
            }

            if ($selectedSection && Schema::hasColumn('students', 'section')) {
                $q->where('section', $selectedSection);
            }

            $studentRecords = $q->get();
        }

        if ($studentRecords->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => $selectedSection
                    ? "No student found with Roll '{$rollInput}' in {$selectedClassName} - Section {$selectedSection}."
                    : 'Student not found. Please check your Roll Number / Student ID.',
            ], 404);
        }

        // ============================================================
        // STEP 4: Find the student whose results EXACTLY match
        //         year + exam (strict match)
        // ============================================================
        $foundStudent = null;
        $foundResults = null;

        foreach ($studentRecords as $student) {
            $results = ResultInput::where('student_id', $student->id) // ✅ এখানে আপডেট
                ->where('academic_year', $year)
                ->where('exam_type', $exam)
                ->get();

            if ($results->isNotEmpty()) {
                $foundStudent = $student;
                $foundResults = $results;
                break;
            }
        }

        if (!$foundStudent || !$foundResults) {
            return response()->json([
                'success' => false,
                'message' => "No result found for this Roll Number, Class, Year and Exam. Please check that the exam ({$exam}) and year ({$year}) are correct.",
            ], 404);
        }

        // ============================================================
        // BUILD RESPONSE — every subject uses 100 as full marks
        // ============================================================
        $studentName = $foundStudent->student_name ?? 'Student';
        $studentRoll = $foundStudent->roll_number ?? $rollInput;
        $studentClass = $foundStudent->class ?? null;
        $studentSection = $foundStudent->section ?? null;

        $class = SchoolClass::where('class_name', $studentClass)
            ->when($studentSection, fn($q, $s) => $q->where('section', $s))
            ->first()
            ?? SchoolClass::find($classId);

        $fullMarks = self::SUBJECT_FULL_MARKS;

        // ✅ SUBJECT-WISE HIGHEST — across the whole class
        $subjectHighest = [];
        if ($studentClass) {
            $highestQuery = ResultInput::query() // ✅ এখানে আপডেট
                ->join('students', 'students.id', '=', 'results.student_id')
                ->where('students.class', $studentClass)
                ->where('results.academic_year', $year)
                ->where('results.exam_type', $exam);

            if ($studentSection) {
                $highestQuery->where('students.section', $studentSection);
            }

            $subjectHighest = $highestQuery
                ->selectRaw('results.subject, MAX(results.marks) as highest')
                ->groupBy('results.subject')
                ->pluck('highest', 'subject')
                ->toArray();
        }

        // ✅ SUBJECTS with per-subject grade, gpa, highest
        $subjects = $foundResults->map(function ($r) use ($fullMarks, $subjectHighest) {
            $obtained = (float) $r->marks;
            $gpa      = $this->marksToGpa($obtained, $fullMarks);
            $grade    = $this->gpaToGrade($gpa);

            return [
                'name'     => $r->subject,
                'full'     => $fullMarks,
                'obtained' => $obtained,
                'grade'    => $grade,
                'gpa'      => $gpa,
                'highest'  => isset($subjectHighest[$r->subject])
                    ? (float) $subjectHighest[$r->subject]
                    : $obtained,
            ];
        })->values();

        // ✅ Totals
        $subjectCount  = $foundResults->count();
        $totalFull     = $subjectCount * $fullMarks;
        $totalObtained = $foundResults->sum('marks');
        $percentage    = $totalFull > 0 ? round(($totalObtained / $totalFull) * 100, 2) : 0;

        // ✅ Overall GPA
        $overallGpa = $subjects->count() > 0
            ? round(min($subjects->avg('gpa'), 5.00), 2)
            : 0;

        $overallGrade = $this->gpaToGrade($overallGpa);
        $hasFailed = $subjects->contains(fn($s) => strtoupper($s['grade']) === 'F');

        // ============================================================
        // ✅ RANKING — compute across ALL students in the class
        // ============================================================
        $allClassStudentIds = DB::table('students')
            ->where('class', $studentClass)
            ->when($studentSection, fn($q, $s) => $q->where('section', $s))
            ->pluck('id')
            ->toArray();

        $allTotals = [];
        $allFails = [];
        if (!empty($allClassStudentIds)) {
            $allTotals = ResultInput::whereIn('student_id', $allClassStudentIds) // ✅ এখানে আপডেট
                ->where('academic_year', $year)
                ->where('exam_type', $exam)
                ->selectRaw('student_id, SUM(marks) as total')
                ->groupBy('student_id')
                ->pluck('total', 'student_id')
                ->toArray();

            $allFails = ResultInput::whereIn('student_id', $allClassStudentIds) // ✅ এখানে আপডেট
                ->where('academic_year', $year)
                ->where('exam_type', $exam)
                ->where('grade', 'F')
                ->selectRaw('student_id, COUNT(*) as fail_count')
                ->groupBy('student_id')
                ->pluck('fail_count', 'student_id')
                ->toArray();
        }

        $rankable = [];
        foreach ($allClassStudentIds as $sid) {
            $rankable[] = [
                'student_id' => $sid,
                'total' => (int) ($allTotals[$sid] ?? 0),
                'fails' => (int) ($allFails[$sid] ?? 0),
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

        $studentPosition = $ranks[$foundStudent->id] ?? null;
        $totalInClass = count($allClassStudentIds);

        // ✅ Student ID / DOB fallbacks
        $studentIdField = $foundStudent->student_id ?? $foundStudent->id ?? '—';
        $dob = $foundStudent->date_of_birth ?? null;

        return response()->json([
            'success' => true,
            'student' => [
                'name'          => $studentName,
                'roll'          => $studentRoll,
                'student_id'    => $studentIdField,
                'class_name'    => $studentClass ?? ($class?->class_name ?? 'N/A'),
                'section'       => $studentSection ?? ($class?->section ?? 'N/A'),
                'exam'          => $exam,
                'year'          => $year,
                'father_name'   => $foundStudent->father_name ?? '—',
                'mother_name'   => $foundStudent->mother_name ?? '—',
                'date_of_birth' => $dob,
                'position'      => $studentPosition,
                'total_students'=> $totalInClass,
            ],
            'subjects' => $subjects,
            'summary'  => [
                'totalMarks'    => $totalFull,
                'obtainedMarks' => (float) $totalObtained,
                'percentage'    => $percentage . '%',
                'grade'         => $hasFailed ? 'F' : $overallGrade,
                'gpa'           => $hasFailed ? 0.00 : $overallGpa,
                'remarks'       => $hasFailed ? 'Failed' : $this->gradeRemarks($overallGrade),
                'passed'        => !$hasFailed,
            ],
        ]);
    }

    private function marksToGpa(float $obtained, float $full): float
    {
        if ($full <= 0) return 0.00;
        $percent = ($obtained / $full) * 100;
        if ($percent >= 80) return 5.00;
        if ($percent >= 70) return 4.00;
        if ($percent >= 60) return 3.50;
        if ($percent >= 50) return 3.00;
        if ($percent >= 40) return 2.00;
        if ($percent >= 33) return 1.00;
        return 0.00;
    }

    private function gpaToGrade($gpa): string
    {
        if ($gpa >= 5.00) return 'A+';
        if ($gpa >= 4.00) return 'A';
        if ($gpa >= 3.50) return 'A-';
        if ($gpa >= 3.00) return 'B';
        if ($gpa >= 2.00) return 'C';
        if ($gpa >= 1.00) return 'D';
        return 'F';
    }

    private function gradeRemarks($grade): string
    {
        return match ($grade) {
            'A+' => 'Excellent',
            'A'  => 'Very Good',
            'A-' => 'Good',
            'B'  => 'Satisfactory',
            'C'  => 'Average',
            'D'  => 'Needs Improvement',
            default => 'Failed',
        };
    }
}