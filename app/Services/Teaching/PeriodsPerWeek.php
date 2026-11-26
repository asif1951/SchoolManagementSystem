<?php

namespace App\Services\Teaching;

use Illuminate\Support\Facades\DB;

/** How many periods a week a subject has in a class: what the routine holds, else its weekly plan, else a sensible 4. */
class PeriodsPerWeek
{
    public function for(int $classId, int $subjectId): int
    {
        $inRoutine = DB::table('class_routine_slots as s')->join('class_routines as r', 'r.id', '=', 's.class_routine_id')
            ->where('r.class_id', $classId)->where('s.subject_id', $subjectId)->count();

        if ($inRoutine) {
            return $inRoutine;
        }

        $planned = (int) DB::table('routine_plans as p')->join('subjects as s', 's.name', '=', 'p.subject')
            ->where('p.class_id', $classId)->where('s.id', $subjectId)->value('p.periods_per_week');

        return $planned ?: 4;
    }
}
