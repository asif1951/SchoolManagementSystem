<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which subjects each student takes in a session. The class's subject plan (class_subjects + group limits) says
 * what exists; this service records the student's side: the study group and the optional / 4th subject(s).
 * Exams and results already read `student_optional_subjects` and `students.study_group_id`, so what is saved here
 * decides who appears on which subject.
 */
class StudentSubjects
{
    /** the most optional (4th) subjects one student may take; config('student.max_optional_subjects') */
    public function maxOptional(): int
    {
        return max(1, (int) config('student.max_optional_subjects', 1));
    }

    /** the subjects of a class split into what is compulsory for a group and what may be chosen */
    public function planFor(SchoolClass $class, ?int $groupId): array
    {
        $groupId ??= $class->study_group_id;

        $compulsory = $optional = [];

        foreach ($class->subjectPlan() as $p) {
            if ($p['group_ids'] !== [] && ($groupId === null || ! in_array($groupId, $p['group_ids'], true))) {
                continue; // limited to other groups
            }

            $row = ['id' => $p['subject_id'], 'name' => $p['name']];
            $p['is_optional'] ? $optional[] = $row : $compulsory[] = $row;
        }

        return ['compulsory' => $compulsory, 'optional' => $optional];
    }

    /** every active student of a class with their group, compulsory subjects and chosen optional ones */
    public function matrix(SchoolClass $class): array
    {
        $students = Student::query()->where('class_id', $class->id)->where('status', 'active')
            ->get(['id', 'student_id', 'student_name', 'roll_number', 'study_group_id'])
            ->sortBy(fn ($s) => [(int) $s->roll_number, $s->student_name])->values();

        $chosen = DB::table('student_optional_subjects')->whereIn('student_id', $students->pluck('id'))->get()->groupBy('student_id');

        $plans = [];

        return [
            'students' => $students->map(function ($s) use ($class, $chosen, &$plans) {
                $g = $s->study_group_id ?? $class->study_group_id;
                $plans[$g ?? 0] ??= $this->planFor($class, $g ? (int) $g : null);

                return [
                    'id' => $s->id, 'code' => $s->student_id, 'name' => $s->student_name, 'roll' => $s->roll_number,
                    'group_id' => $s->study_group_id, 'effective_group_id' => $g,
                    'optional' => ($chosen->get($s->id) ?? collect())->pluck('subject_id')->map(fn ($i) => (int) $i)->values()->all(),
                    'compulsory' => array_column($plans[$g ?? 0]['compulsory'], 'name'),
                ];
            })->all(),
            'optionalSubjects' => $this->planFor($class, null)['optional'],
            'allOptional' => collect($class->subjectPlan())->where('is_optional', true)->map(fn ($p) => ['id' => $p['subject_id'], 'name' => $p['name'], 'group_ids' => $p['group_ids']])->values()->all(),
            'groups' => $class->study_group_id ? [] : StudyGroup::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->all(),
            'max' => $this->maxOptional(),
        ];
    }

    /**
     * Set one student's group and optional subjects.
     *
     * @param  array<int,int>  $optionalIds
     */
    public function assign(Student $student, ?int $groupId, array $optionalIds, User $actor): Student
    {
        $class = $student->schoolClass ?? throw ValidationException::withMessages(['student' => 'The student has no class.']);

        $this->authorize($actor, $class);
        $this->guardStudent($student);

        if ($class->study_group_id !== null && $groupId !== null && $groupId !== (int) $class->study_group_id) {
            throw ValidationException::withMessages(['group' => 'This class belongs to one study group; the student cannot be in another.']);
        }

        if ($groupId !== null && ! StudyGroup::where('id', $groupId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['group' => 'Unknown study group.']);
        }

        $optionalIds = array_values(array_unique(array_map('intval', $optionalIds)));

        if (count($optionalIds) > $this->maxOptional()) {
            throw ValidationException::withMessages(['optional' => "At most {$this->maxOptional()} optional subject(s) can be chosen."]);
        }

        $allowed = array_column($this->planFor($class, $groupId)['optional'], 'id');

        if (array_diff($optionalIds, $allowed) !== []) {
            throw ValidationException::withMessages(['optional' => 'A chosen subject is not an optional subject of this class for the student\'s group.']);
        }

        DB::transaction(function () use ($student, $groupId, $optionalIds, $actor) {
            $before = ['group' => $student->study_group_id, 'optional' => DB::table('student_optional_subjects')->where('student_id', $student->id)->orderBy('subject_id')->pluck('subject_id')->all()];

            $student->forceFill(['study_group_id' => $groupId])->save();

            DB::table('student_optional_subjects')->where('student_id', $student->id)->whereNotIn('subject_id', $optionalIds ?: [0])->delete();

            foreach ($optionalIds as $id) {
                DB::table('student_optional_subjects')->updateOrInsert(['student_id' => $student->id, 'subject_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
            }

            sort($optionalIds);

            if ($before !== ['group' => $groupId, 'optional' => $optionalIds]) {
                AuditLog::record($student, 'subjects_assigned', ['from' => $before, 'to' => ['group' => $groupId, 'optional' => $optionalIds]], $actor->id);
            }
        });

        return $student->refresh();
    }

    /**
     * Put many students of a class into a group, or give them all one optional subject, in one go.
     *
     * @param  array<int,int>  $studentIds
     * @return int how many students changed
     */
    public function bulk(SchoolClass $class, array $studentIds, ?int $groupId, ?int $optionalId, User $actor, bool $replaceOptional = true): int
    {
        $this->authorize($actor, $class);

        $students = Student::where('class_id', $class->id)->whereIn('id', $studentIds)->get();

        if ($students->count() !== count(array_unique($studentIds))) {
            throw ValidationException::withMessages(['students' => 'Some students do not belong to this class.']);
        }

        $changed = 0;

        DB::transaction(function () use ($students, $groupId, $optionalId, $actor, $replaceOptional, &$changed) {
            foreach ($students as $s) {
                $current = DB::table('student_optional_subjects')->where('student_id', $s->id)->pluck('subject_id')->map(fn ($i) => (int) $i)->all();
                $next = $optionalId === null ? $current : ($replaceOptional ? [$optionalId] : array_values(array_unique([...$current, $optionalId])));
                $group = $groupId ?? ($s->study_group_id ? (int) $s->study_group_id : null);

                // a subject that does not fit the group is dropped instead of failing the whole batch
                $allowed = array_column($this->planFor($s->schoolClass, $group)['optional'], 'id');
                $next = array_values(array_intersect($next, $allowed));

                if ($group !== (int) $s->study_group_id || $next !== $current) {
                    $this->assign($s, $group, array_slice($next, 0, $this->maxOptional()), $actor);
                    $changed++;
                }
            }
        });

        return $changed;
    }

    private function authorize(User $actor, SchoolClass $class): void
    {
        if (! $actor->canDo('students.manage_subjects', $class)) {
            throw new AuthorizationException('You may not assign subjects in this class.');
        }
    }

    private function guardStudent(Student $student): void
    {
        if ($student->status === 'transferred') {
            throw ValidationException::withMessages(['student' => 'A transferred student cannot be changed.']);
        }
    }
}
