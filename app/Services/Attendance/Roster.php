<?php

namespace App\Services\Attendance;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who belongs to a class on a date: admitted by then, not yet left, in this class that day (class changes are kept in
 * student_class_moves), and, for a subject period, taking the subject.
 */
class Roster
{
    /** @return Collection<int,Student> in roll order */
    public function students(SchoolClass $class, string $date, ?int $subjectId = null): Collection
    {
        // students who were here that day but have since moved to another class
        $movedAway = DB::table('student_class_moves')->where('from_class_id', $class->id)->where('moved_on', '>', $date)->pluck('student_id');

        $students = Student::query()
            ->where(fn ($q) => $q->where('class_id', $class->id)->orWhereIn('id', $movedAway))
            ->where(fn ($q) => $q->whereNull('admission_date')->orWhereDate('admission_date', '<=', $date))
            // someone who has left counts up to the day they left
            ->where(fn ($q) => $q->where('status', 'active')->orWhereDate('status_changed_on', '>=', $date))
            ->get();

        $moves = DB::table('student_class_moves')->whereIn('student_id', $students->pluck('id'))->where('moved_on', '>', $date)->orderBy('moved_on')->orderBy('id')->get()->groupBy('student_id');

        // the class on that date: where the first later move came from, else the present class
        $students = $students->filter(fn ($s) => (int) (isset($moves[$s->id]) ? $moves[$s->id]->first()->from_class_id : $s->class_id) === (int) $class->id);

        if ($subjectId) {
            $students = $this->takingSubject($class, $students, $subjectId);
        }

        return $this->sort($students);
    }

    public function sort(Collection $students): Collection
    {
        return $students->sortBy(fn ($s) => [blank($s->roll_number) ? 1 : 0, (int) preg_replace('/\D/', '', (string) $s->roll_number), (string) $s->roll_number, $s->student_name])->values();
    }

    /** the class's subject plan says who takes it: group limits, and the optional subjects each student chose */
    private function takingSubject(SchoolClass $class, Collection $students, int $subjectId): Collection
    {
        $plan = collect($class->subjectPlan())->firstWhere('subject_id', $subjectId);

        if (! $plan) {
            return $students;
        }

        $chosen = $plan['is_optional'] ? DB::table('student_optional_subjects')->where('subject_id', $subjectId)->whereIn('student_id', $students->pluck('id'))->pluck('student_id')->flip() : null;

        return $students->filter(function ($s) use ($class, $plan, $chosen) {
            $group = $s->study_group_id ?? $class->study_group_id;

            if ($plan['group_ids'] !== [] && ($group === null || ! in_array((int) $group, array_map('intval', $plan['group_ids']), true))) {
                return false;
            }

            return $chosen === null || isset($chosen[$s->id]);
        });
    }
}
