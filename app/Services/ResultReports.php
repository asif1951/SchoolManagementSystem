<?php

namespace App\Services;

use App\Models\ResultPolicy;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The data behind the printed result documents other than the tabulation sheet:
 *
 *   marksheets    one page per student: subjects, marks, grades, GPA, position, attendance
 *   classSummary  one class: the result at a glance, grade spread, subject by subject, merit list, failures
 *   schoolResult  every class of a year side by side (class-wise result)
 *
 * They are all built from TabulationBuilder, so a figure is the same on every document.
 */
class ResultReports
{
    public function __construct(private TabulationBuilder $builder)
    {
    }

    // ------------------------------------------------------------------
    // marksheets
    // ------------------------------------------------------------------

    /**
     * @param  array<int,int>|null  $studentIds  only these students (null = the whole class)
     */
    public function marksheets(SchoolClass $class, string $examType, ?array $studentIds = null): array
    {
        $t      = $this->builder->build($class, $examType);
        $scale  = ResultPolicy::forYear((string) $class->academic_year)->scale()->load('rows');
        $people = Student::query()->whereIn('id', collect($t['rows'])->pluck('id'))->get()->keyBy('id');

        $ranked = collect($t['rows'])->whereNotNull('rank')->count();
        $sheets = [];

        foreach ($t['rows'] as $r) {
            if ($studentIds !== null && ! in_array($r['id'], $studentIds, true)) {
                continue;
            }

            $student = $people[$r['id']];
            $lines   = [];
            $failed  = [];
            $missing = [];
            $taken   = 0;

            foreach ($t['subjects'] as $s) {
                $cell = $r['cells'][$s['id']] ?? false;

                if ($cell === false) {
                    continue;                                   // the student does not take it
                }

                $g = $cell['mark'] === null ? null : $scale->gradeFor($cell['mark']);

                $lines[] = [
                    'name'     => $s['name'],
                    'optional' => $s['optional'],
                    'full'     => 100,
                    'mark'     => $cell['mark'],
                    'grade'    => $g['grade'] ?? null,
                    'point'    => $g['point'] ?? null,
                    'fail'     => $cell['fail'],
                    'highest'  => $t['highest'][$s['id']] ?? null,
                ];

                $taken++;

                if ($cell['fail'] && ! $s['optional']) {
                    $failed[] = $s['name'];
                }

                if ($cell['mark'] === null && ! $s['optional']) {
                    $missing[] = $s['name'];
                }
            }

            $sheets[] = [
                'student' => [
                    'name'    => $student->student_name,
                    'uid'     => $student->student_id,
                    'roll'    => $student->roll_number,
                    'father'  => $student->father_name,
                    'mother'  => $student->mother_name,
                    'dob'     => $student->date_of_birth,
                    'gender'  => $student->gender,
                    'photo'   => $student->student_photo && \Illuminate\Support\Facades\Storage::disk('public')->exists($student->student_photo) ? $student->student_photo : null,
                ],
                'lines'         => $lines,
                'full_total'    => $taken * 100,
                'subject_total' => $r['subject_total'],
                'att_marks'     => $r['att_marks'],
                'total'         => $r['total'],
                'gpa'           => $r['gpa'],
                'grade'         => $r['grade'],
                'status'        => $r['status'],
                'rank'          => $r['rank'],
                'ranked'        => $ranked,
                'present'       => $r['present'],
                'att_percent'   => $r['att_percent'],
                'remarks'       => match ($r['status']) {
                    'Failed'     => 'Failed in ' . implode(', ', $failed),
                    'Incomplete' => $missing ? 'Result withheld: marks not entered for ' . implode(', ', $missing) : 'Result withheld',
                    default      => '',
                },
            ];
        }

        return [
            'school'           => $t['school'],
            'exam'             => $t['exam'],
            'year'             => $t['year'],
            'class'            => $t['class'],
            'working_days'     => $t['working_days'],
            'attendance_marks' => $t['attendance_marks'],
            'scale'            => $t['scale'],
            'grading'          => $t['grading'],
            'sheets'           => $sheets,
            'generated_at'     => $t['generated_at'],
        ];
    }

    // ------------------------------------------------------------------
    // class result summary
    // ------------------------------------------------------------------

    public function classSummary(SchoolClass $class, string $examType): array
    {
        $t    = $this->builder->build($class, $examType);
        $rows = collect($t['rows']);

        $scale   = ResultPolicy::forYear((string) $class->academic_year)->scale()->load('rows');
        $appeared = $rows->where('status', '!=', 'Incomplete');

        // ---- grade spread (overall grade of every student whose result is complete) ----
        $grades = $this->gradeOrder($scale)->map(fn ($g) => [
            'grade' => $g,
            'count' => $appeared->where('grade', $g)->count(),
            'percent' => $appeared->count() ? round($appeared->where('grade', $g)->count() / $appeared->count() * 100, 1) : null,
        ])->values()->all();

        // ---- subject by subject ----
        $teachers = $class->currentSubjectTeacherLists();                      // a subject may have several teachers

        $subjects = [];
        foreach ($t['subjects'] as $s) {
            $cells   = $rows->map(fn ($r) => $r['cells'][$s['id']])->filter(fn ($c) => $c !== false);
            $marked  = $cells->filter(fn ($c) => $c['mark'] !== null);
            $failed  = $marked->where('fail', true)->count();
            $marks   = $marked->pluck('mark');

            $subjects[] = [
                'name'      => $s['name'],
                'optional'  => $s['optional'],
                'teacher'   => ($teachers->get($s['name']) ?? collect())->pluck('teacher_name')->unique()->implode(', ') ?: null,
                'taking'    => $cells->count(),
                'appeared'  => $marked->count(),
                'passed'    => $marked->count() - $failed,
                'failed'    => $failed,
                'pass_rate' => $marked->count() ? round(($marked->count() - $failed) / $marked->count() * 100, 1) : null,
                'highest'   => $marks->isEmpty() ? null : $marks->max(),
                'lowest'    => $marks->isEmpty() ? null : $marks->min(),
                'average'   => $marks->isEmpty() ? null : round($marks->avg(), 1),
            ];
        }

        // ---- merit list and failures ----
        $merit = $rows->whereNotNull('rank')->where('status', 'Passed')->sortBy([['rank', 'asc'], ['roll', 'asc']])
            ->filter(fn ($r) => $r['rank'] <= 10)->take(15)->values()
            ->map(fn ($r) => \Illuminate\Support\Arr::only($r, ['rank', 'roll', 'name', 'uid', 'total', 'gpa', 'grade']))->all();

        $subjectName = collect($t['subjects'])->pluck('name', 'id');

        $failures = $rows->where('status', 'Failed')->values()->map(fn ($r) => [
            'roll' => $r['roll'], 'name' => $r['name'], 'uid' => $r['uid'],
            'subjects' => collect($r['cells'])->filter(fn ($c) => $c !== false && $c['fail'])->keys()->map(fn ($id) => $subjectName[$id])->implode(', '),
        ])->all();

        $attendance = $rows->pluck('att_percent')->filter(fn ($v) => $v !== null);

        return [
            'school'       => $t['school'],
            'exam'         => $t['exam'],
            'year'         => $t['year'],
            'class'        => $t['class'],
            'working_days' => $t['working_days'],
            'summary'      => $t['summary'] + [
                'highest_total' => $appeared->max('total'),
                'highest_gpa'   => $appeared->where('status', 'Passed')->max('gpa'),
                'attendance'    => $attendance->isEmpty() ? null : round($attendance->avg(), 1),
            ],
            'grades'       => $grades,
            'subjects'     => $subjects,
            'merit'        => $merit,
            'failures'     => $failures,
            'scale'        => $t['scale'],
            'grading'      => $t['grading'],
            'generated_at' => $t['generated_at'],
        ];
    }

    // ------------------------------------------------------------------
    // class-wise result of a whole year
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int,SchoolClass>  $classes  the classes to include (already limited to what the user may see)
     */
    public function schoolResult(string $year, string $examType, Collection $classes): array
    {
        $scale  = ResultPolicy::forYear($year)->scale()->load('rows');
        $grades = $this->gradeOrder($scale);

        $make = function (Collection $rows, array $base) use ($grades) {
            $appeared = $rows->where('status', '!=', 'Incomplete');
            $passed   = $rows->where('status', 'Passed');

            return $base + [
                'students'   => $rows->count(),
                'appeared'   => $appeared->count(),
                'passed'     => $passed->count(),
                'failed'     => $rows->where('status', 'Failed')->count(),
                'incomplete' => $rows->where('status', 'Incomplete')->count(),
                'pass_rate'  => $appeared->count() ? round($passed->count() / $appeared->count() * 100, 1) : null,
                'average_gpa' => $passed->isEmpty() ? null : round($passed->avg('gpa'), 2),
                'highest_gpa' => $passed->isEmpty() ? null : $passed->max('gpa'),
                'grades'     => $grades->mapWithKeys(fn ($g) => [$g => $appeared->where('grade', $g)->count()])->all(),
            ];
        };

        $perClass = [];

        foreach ($classes as $class) {
            $t    = $this->builder->build($class, $examType);
            $rows = collect($t['rows']);
            $top  = $rows->where('rank', 1)->first();

            $perClass[] = [
                'rows'  => $rows,
                'entry' => $make($rows, [
                    'class'   => $class->class_label,
                    'section' => $class->section,
                    'group'   => $class->group_name,
                    'shift'   => $class->shift_name,
                    'medium'  => $class->medium_name,
                    'teacher' => $class->class_teacher,
                    'topper'  => $top ? ['name' => $top['name'], 'roll' => $top['roll'], 'total' => $top['total']] : null,
                ]),
            ];
        }

        // natural order (Class 2 before Class 10), then section
        usort($perClass, function ($a, $b) {
            return strnatcasecmp($a['entry']['class'], $b['entry']['class'])
                ?: strnatcasecmp((string) $a['entry']['group'], (string) $b['entry']['group'])
                ?: strnatcasecmp((string) $a['entry']['section'], (string) $b['entry']['section'])
                ?: strnatcasecmp((string) $a['entry']['shift'], (string) $b['entry']['shift'])
                ?: strnatcasecmp((string) $a['entry']['medium'], (string) $b['entry']['medium']);
        });

        // one block per class name, with a subtotal when it has several sections
        $blocks = [];
        foreach (collect($perClass)->groupBy(fn ($c) => $c['entry']['class']) as $name => $items) {
            $blocks[] = [
                'name'     => $name,
                'entries'  => $items->pluck('entry')->all(),
                'subtotal' => $items->count() > 1
                    ? $make($items->flatMap(fn ($i) => $i['rows'])->values(), ['class' => $name])
                    : null,
            ];
        }

        $everyone = collect($perClass)->flatMap(fn ($c) => $c['rows'])->values();

        return [
            'school'       => $this->builder->school(),
            'exam'         => ['value' => $examType, 'label' => TabulationBuilder::EXAM_TYPES[$examType] ?? ucwords(str_replace('_', ' ', $examType))],
            'year'         => $year,
            'grades'       => $grades->all(),
            'blocks'       => $blocks,
            'total'        => $make($everyone, ['class' => 'Total']),
            'class_count'  => count($perClass),
            'grading'      => $this->builder->gradingTable($scale),
            'scale'        => $scale->rows->map(fn ($r) => ['min' => (float) $r->min_percent, 'grade' => $r->grade, 'point' => (float) $r->grade_point])->all(),
            'generated_at' => now(),
        ];
    }

    /** Overall grades from the best to the failing one, each once. */
    private function gradeOrder($scale): Collection
    {
        $pass = $scale->rows->where('is_fail', false)->sortByDesc('grade_point')->pluck('grade');
        $fail = $scale->rows->where('is_fail', true)->pluck('grade');

        return $pass->merge($fail)->unique()->values();
    }
}
