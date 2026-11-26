<?php

namespace App\Services\Teaching;

use App\Models\SchoolClass;
use App\Models\Teaching\TeachingPlan;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The lean read queries behind lists and dashboards: plain joins selecting only what is shown (no model loading,
 * no query per row).
 */
class Rows
{
    /** @var array<string,array<int,array{id:int,label:string,name:string}>> */
    private array $classCache = [];

    /** @return array<int,array{id:int,label:string,name:string}> the session's classes in natural order */
    public function classes(string $year): array
    {
        return $this->classCache[$year] ??= DB::table('classes')->where('academic_year', $year)->get(['id', 'class_name', 'class_title', 'section', 'section_title'])
            ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))
            ->map(fn ($c) => ['id' => (int) $c->id, 'label' => $this->label($c), 'name' => $c->class_name])->values()->all();
    }

    public function label(object $c): string
    {
        return SchoolClass::joinLabel($c->class_name, $c->class_title) . ($c->section ? ' — ' . SchoolClass::joinLabel($c->section, $c->section_title) : '');
    }

    /** teaching_plans joined with the names a list shows; filters: teacher, class, subject, health, status, delayed */
    public function plans(string $year, array $filters): Builder
    {
        return DB::table('teaching_plans as p')
            ->join('classes as c', 'c.id', '=', 'p.class_id')->join('subjects as s', 's.id', '=', 'p.subject_id')->join('users as u', 'u.id', '=', 'p.teacher_id')
            ->where('p.academic_year', $year)
            ->when($filters['teacher'] ?? null, fn ($q, $v) => $q->where('p.teacher_id', $v))
            ->when($filters['class'] ?? null, fn ($q, $v) => $q->where('p.class_id', $v))
            ->when($filters['subject'] ?? null, fn ($q, $v) => $q->where('p.subject_id', $v))
            ->when($filters['health'] ?? null, fn ($q, $v) => $q->where('p.health', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('p.status', $v))
            ->when($filters['delayed'] ?? null, fn ($q) => $q->where('p.delayed', '>', 0))
            ->select(['p.id', 'p.academic_year', 'p.status', 'p.class_id', 'p.subject_id', 'p.teacher_id', 'p.chapters', 'p.delayed', 'p.planned_periods', 'p.done_pct', 'p.expected_pct',
                'p.gap', 'p.health', 'p.projected_finish', 'p.last_log_on', 'c.class_name', 'c.class_title', 'c.section', 'c.section_title', 's.name as subject', 'u.name as teacher']);
    }

    /** @param array<int,object> $rows */
    public function present(array $rows): array
    {
        return array_map(fn ($r) => [
            'id' => (int) $r->id, 'year' => $r->academic_year, 'status' => $r->status, 'class_id' => (int) $r->class_id, 'subject_id' => (int) $r->subject_id, 'teacher_id' => (int) $r->teacher_id,
            'class' => $this->label($r), 'subject' => $r->subject, 'teacher' => $r->teacher, 'chapters' => (int) $r->chapters, 'delayed' => (int) $r->delayed,
            'periods' => (int) $r->planned_periods, 'done' => (float) $r->done_pct, 'expected' => (float) $r->expected_pct, 'gap' => (float) $r->gap, 'health' => $r->health,
            'finish' => $r->projected_finish ? substr($r->projected_finish, 0, 10) : null, 'last_log' => $r->last_log_on ? substr($r->last_log_on, 0, 10) : null,
        ], $rows);
    }

    /** does the class module give this teacher this subject of this class? */
    public function assigned(int $teacherId, int $classId, int $subjectId): bool
    {
        return DB::table('class_subject_teachers as t')->join('subjects as s', 's.name', '=', 't.subject')
            ->where('t.user_id', $teacherId)->where('t.class_id', $classId)->where('s.id', $subjectId)->whereNull('t.ends_on')->exists();
    }

    /** subject assignments of the session that have no plan yet (for one teacher, or the first 30 of the school) */
    public function missing(string $year, ?int $teacherId, int $limit = 30): array
    {
        return DB::table('class_subject_teachers as t')
            ->join('classes as c', 'c.id', '=', 't.class_id')->join('subjects as s', 's.name', '=', 't.subject')->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('teaching_plans as p', fn ($j) => $j->on('p.class_id', '=', 't.class_id')->on('p.subject_id', '=', 's.id')->on('p.teacher_id', '=', 't.user_id'))
            ->whereNull('t.ends_on')->whereNull('p.id')->where('c.academic_year', $year)->where('u.status', User::STATUS_ACTIVE)
            ->when($teacherId, fn ($q) => $q->where('t.user_id', $teacherId))
            ->orderBy('c.class_name')->orderBy('c.section')->orderBy('s.name')->limit($limit)
            ->get(['t.class_id', 's.id as subject_id', 't.user_id as teacher_id', 's.name as subject', 'u.name as teacher', 'c.class_name', 'c.class_title', 'c.section', 'c.section_title'])
            ->map(fn ($r) => ['class_id' => (int) $r->class_id, 'subject_id' => (int) $r->subject_id, 'teacher_id' => (int) $r->teacher_id, 'class' => $this->label($r), 'subject' => $r->subject, 'teacher' => $r->teacher])->all();
    }

    /** plans of the same subject and class level (last year, other sections) whose dates can be copied */
    public function copySources(TeachingPlan $plan): array
    {
        $className = DB::table('classes')->where('id', $plan->class_id)->value('class_name');
        $rows = DB::table('teaching_plans as p')->join('classes as c', 'c.id', '=', 'p.class_id')->join('users as u', 'u.id', '=', 'p.teacher_id')
            ->where('p.subject_id', $plan->subject_id)->where('p.id', '!=', $plan->id)->where('c.class_name', $className)
            // own plans, or anyone's for the same subject and level (dates only are copied)
            ->where('p.teacher_id', $plan->teacher_id)->orderByDesc('p.academic_year')->limit(20)
            ->get(['p.id', 'p.academic_year', 'c.class_name', 'c.class_title', 'c.section', 'c.section_title', 'u.name as teacher']);

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'label' => $this->label($r) . " · {$r->academic_year} · {$r->teacher}"])->all();
    }

    /** other sections of this class level this year that this teacher also teaches */
    public function copyTargets(TeachingPlan $plan, User $user): array
    {
        $className = DB::table('classes')->where('id', $plan->class_id)->value('class_name');

        return DB::table('classes as c')->join('class_subject_teachers as t', fn ($j) => $j->on('t.class_id', '=', 'c.id'))->join('subjects as s', 's.name', '=', 't.subject')
            ->where('c.academic_year', $plan->academic_year)->where('c.class_name', $className)->where('c.id', '!=', $plan->class_id)
            ->where('s.id', $plan->subject_id)->where('t.user_id', $plan->teacher_id)->whereNull('t.ends_on')
            ->distinct()->get(['c.id', 'c.class_name', 'c.class_title', 'c.section', 'c.section_title'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'label' => $this->label($r)])->all();
    }
}
