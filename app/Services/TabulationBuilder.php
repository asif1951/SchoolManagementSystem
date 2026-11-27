<?php

namespace App\Services;

use App\Services\Attendance\Calculator;
use App\Models\GradingScale;
use App\Models\ResultInput;
use App\Models\ResultPolicy;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\WebsiteSetting;
use Illuminate\Support\Facades\DB;

/**
 * Builds the data of a class's tabulation sheet from what is already in the system:
 * the marks, the attendance records, the year's result rules and grading scale.
 * Nothing here is typed by hand, so the sheet always matches the records.
 *
 * Marks are out of 100 per subject. A student takes the compulsory subjects of their group
 * plus the optional subject they chose, and only those columns are filled for them.
 */
class TabulationBuilder
{
    public const EXAM_TYPES = [
        'first_terminal'  => 'First Terminal Examination',
        'second_terminal' => 'Second Terminal Examination',
        'final'           => 'Final Examination',
        'model_test'      => 'Model Test',
    ];

    /** subjects shown on one printed sheet (more than this continue on another sheet) */
    public const SUBJECTS_PER_SHEET = 10;

    public function build(SchoolClass $class, string $examType, int $subjectsPerSheet = self::SUBJECTS_PER_SHEET): array
    {
        $year   = (string) $class->academic_year;
        $policy = ResultPolicy::forYear($year);
        $scale  = $policy->scale()->load('rows');

        $subjectsPerSheet = max(3, min(20, $subjectsPerSheet));

        // ---- who and what ---------------------------------------------------------------
        $students = Student::query()
            ->where('class_id', $class->id)
            ->where('status', 'active')
            ->get()
            ->sortBy(fn ($s) => [(int) $s->roll_number, $s->student_name])
            ->values();

        $chosen = DB::table('student_optional_subjects')
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('subject_id')->all());

        $plan = collect($class->subjectPlan());

        $takes = function (Student $student, array $p) use ($class, $chosen): bool {
            $group = $student->study_group_id ?? $class->study_group_id;

            $inGroup = $p['group_ids'] === [] || ($group !== null && in_array($group, $p['group_ids'], true));
            $picked  = ! $p['is_optional'] || in_array($p['subject_id'], $chosen->get($student->id, []), true);

            return $inGroup && $picked;
        };

        // keep only subjects that at least one student takes
        $plan = $plan->filter(fn ($p) => $students->contains(fn ($s) => $takes($s, $p)))->values();

        $labels = Subject::query()->whereIn('id', $plan->pluck('subject_id'))->get(['id', 'name', 'short_name'])->keyBy('id');

        $subjects = $plan->map(fn ($p) => [
            'id'       => $p['subject_id'],
            'name'     => $p['name'],
            'label'    => $labels[$p['subject_id']]->short_name ?: $p['name'],
            'optional' => $p['is_optional'],
        ])->all();

        // ---- marks ----------------------------------------------------------------------
        $marks = ResultInput::query()
            ->where('class_id', $class->id)
            ->where('exam_type', $examType)
            ->where('academic_year', $year)
            ->get(['student_id', 'subject', 'marks'])
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('marks', 'subject')->map(fn ($m) => (float) $m)->all());

        // ---- attendance (the attendance module's own calculation) ------------------------
        $session = ['from' => substr($year, 0, 4) . '-01-01', 'to' => substr($year, 0, 4) . '-12-31', 'class_ids' => [$class->id]];
        $calculator = app(Calculator::class);
        $attendance = $calculator->students($session);
        $workingDays = (int) $calculator->query($session)->distinct()->count('r.date');

        $attendanceMarks = (float) $policy->attendance_marks;
        $topPoint        = (float) $scale->rows->max('grade_point');

        // ---- one row per student --------------------------------------------------------
        $rows = [];

        foreach ($students as $student) {
            $cells   = [];
            $total   = 0.0;
            $counted = [];                       // [optional?, mark|null] of the subjects this student takes

            foreach ($plan as $p) {
                if (! $takes($student, $p)) {
                    $cells[$p['subject_id']] = false;                       // not taken
                    continue;
                }

                $mark = $marks->get($student->id, [])[$p['name']] ?? null;
                $fail = $mark !== null && $scale->gradeFor($mark)['is_fail'];

                $cells[$p['subject_id']] = ['mark' => $mark, 'fail' => $fail];
                $counted[] = ['optional' => $p['is_optional'], 'mark' => $mark];

                if ($mark !== null) {
                    $total += $mark;
                }
            }

            // attendance
            // days attended (a period-wise day counts by the share of its periods attended)
            $att      = $attendance[$student->id] ?? null;
            $days     = $att ? round($att['attended_days'], 1) : 0;
            $present  = $days == (int) $days ? (int) $days : $days;
            $percent  = $att && $att['percent'] !== null ? round($att['percent'], 1) : null;
            $attMarks = ($attendanceMarks > 0 && $percent !== null) ? round($percent / 100 * $attendanceMarks, 1) : 0.0;

            $result = $this->evaluate($counted, $policy, $scale, $topPoint);

            $rows[] = [
                'id'          => $student->id,
                'roll'        => $student->roll_number,
                'name'        => $student->student_name,
                'uid'         => $student->student_id,
                'cells'       => $cells,
                'total'       => $total + $attMarks,
                'subject_total' => $total,
                'gpa'         => $result['gpa'],
                'grade'       => $result['grade'],
                'failed'      => $result['failed'],
                'status'      => $result['status'],
                'present'     => $present,
                'att_percent' => $percent,
                'att_marks'   => $attMarks,
                'rank'        => null,
            ];
        }

        $this->rank($rows);

        // ---- class figures --------------------------------------------------------------
        $highest = [];
        foreach ($subjects as $s) {
            $values = collect($rows)->map(fn ($r) => $r['cells'][$s['id']]['mark'] ?? null)->filter(fn ($v) => $v !== null);
            $highest[$s['id']] = $values->isEmpty() ? null : $values->max();
        }

        $complete = collect($rows)->where('status', '!=', 'Incomplete');
        $passed   = $complete->where('status', 'Passed')->count();

        return [
            'school' => $this->school(),
            'exam'     => ['value' => $examType, 'label' => self::EXAM_TYPES[$examType] ?? ucwords(str_replace('_', ' ', $examType))],
            'class'    => [
                'id'      => $class->id,
                'name'    => $class->class_label,
                'section' => $class->section,
                'group'   => $class->group_name,
                'shift'   => $class->shift_name ?? null,
                'medium'  => $class->medium_name ?? null,
                'teacher' => $class->class_teacher,
            ],
            'year'        => $year,
            'subjects'    => $subjects,
            'sheets'      => array_chunk(array_column($subjects, 'id'), $subjectsPerSheet),
            'rows'        => $rows,
            'highest'     => $highest,
            'working_days' => $workingDays,
            'attendance_marks' => $attendanceMarks,
            'grading'     => $this->gradingTable($scale),
            'scale'       => $scale->rows->map(fn ($r) => [
                'min' => (float) $r->min_percent, 'grade' => $r->grade, 'point' => (float) $r->grade_point,
            ])->all(),
            'summary'     => [
                'students'   => count($rows),
                'appeared'   => $complete->count(),
                'passed'     => $passed,
                'failed'     => $complete->where('status', 'Failed')->count(),
                'incomplete' => count($rows) - $complete->count(),
                'pass_rate'  => $complete->count() ? round($passed / $complete->count() * 100, 1) : null,
                'average_gpa' => $complete->where('status', 'Passed')->avg('gpa'),
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * The grading scale as printed: the marks range of each grade with its letter and point.
     * Ranges run from a grade's minimum up to the next grade's minimum (80 – 100, 70 – 79 …).
     *
     * @return array<int,array{range:string, grade:string, point:float, is_fail:bool}>
     */
    public function gradingTable(GradingScale $scale): array
    {
        $fmt  = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $rows = $scale->rows->sortByDesc('min_percent')->values();
        $out  = [];

        foreach ($rows as $i => $r) {
            $min   = (float) $r->min_percent;
            $above = $i === 0 ? null : (float) $rows[$i - 1]->min_percent;

            $range = match (true) {
                $above === null                    => $fmt($min) . ' – 100',
                floor($min) == $min && floor($above) == $above => $fmt($min) . ' – ' . $fmt($above - 1),
                default                            => $fmt($min) . ' – below ' . $fmt($above),
            };

            $out[] = ['range' => $range, 'grade' => $r->grade, 'point' => (float) $r->grade_point, 'is_fail' => (bool) $r->is_fail];
        }

        return $out;
    }

    /** The school's name, address and logo as printed at the top of every document. */
    public function school(): array
    {
        $setting = WebsiteSetting::first();

        return [
            'name'    => $setting?->school_name ?: config('app.name'),
            'address' => $setting?->address,
            'phone'   => $setting?->phone,
            'email'   => $setting?->email,
            'logo'    => $setting?->school_logo && \Illuminate\Support\Facades\Storage::disk('public')->exists($setting->school_logo) ? $setting->school_logo : null,
            'color'   => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $setting?->print_color) ? $setting->print_color : '#1f3a5f',
        ];
    }

    /**
     * Result of one student from their subjects.
     * Optional ("4th") subject: with the above-threshold rule only the points above the threshold
     * are added and a fail in it never fails the student.
     *
     * @param  array<int,array{optional:bool,mark:?float}>  $taken
     * @return array{gpa:?float, grade:?string, failed:int, status:string}
     */
    public function evaluate(array $taken, ResultPolicy $policy, GradingScale $scale, float $topPoint): array
    {
        $sum = 0.0;
        $count = 0;
        $failed = 0;
        $extra = 0.0;
        $missing = false;

        foreach ($taken as $t) {
            $special = $t['optional'] && $policy->optional_rule === ResultPolicy::OPTIONAL_ABOVE;

            if ($t['mark'] === null) {
                $missing = $missing || ! $special;       // a missing optional mark is simply ignored
                continue;
            }

            $g = $scale->gradeFor($t['mark']);

            if ($special) {
                $extra = max($extra, max(0.0, $g['point'] - (float) $policy->optional_threshold));
                continue;
            }

            $count++;
            $sum += $g['point'];
            $failed += $g['is_fail'] ? 1 : 0;
        }

        if ($missing || $count === 0) {
            return ['gpa' => null, 'grade' => null, 'failed' => $failed, 'status' => 'Incomplete'];
        }

        if ($failed > 0) {
            return ['gpa' => 0.0, 'grade' => $this->failGrade($scale), 'failed' => $failed, 'status' => 'Failed'];
        }

        $gpa = round(min($topPoint, ($sum + $extra) / $count), 2);

        return ['gpa' => $gpa, 'grade' => $this->letterFor($gpa, $scale), 'failed' => 0, 'status' => 'Passed'];
    }

    /** Overall letter for a GPA: the best non-failing grade whose point does not exceed the GPA. */
    private function letterFor(float $gpa, GradingScale $scale): string
    {
        $row = $scale->rows->where('is_fail', false)->sortByDesc('grade_point')->first(fn ($r) => (float) $r->grade_point <= $gpa + 0.00001);

        return $row?->grade ?? $this->failGrade($scale);
    }

    private function failGrade(GradingScale $scale): string
    {
        return (string) ($scale->rows->firstWhere('is_fail', true)?->grade ?? 'F');
    }

    /** Position: passed students first (by total), then failed students; incomplete ones get none. Equal results share a rank. */
    private function rank(array &$rows): void
    {
        $order = collect($rows)
            ->filter(fn ($r) => $r['status'] !== 'Incomplete')
            ->sortBy(fn ($r) => [$r['failed'], -$r['total']])
            ->keys()
            ->all();

        $position = 0;
        $rank = 0;
        $previous = null;

        foreach ($order as $i) {
            $position++;
            $key = $rows[$i]['failed'] . '|' . $rows[$i]['total'];

            if ($key !== $previous) {
                $rank = $position;
                $previous = $key;
            }

            $rows[$i]['rank'] = $rank;
        }
    }
}
