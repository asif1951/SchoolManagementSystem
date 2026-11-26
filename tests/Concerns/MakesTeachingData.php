<?php

namespace Tests\Concerns;

use App\Models\RoutinePeriod;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teaching\SyllabusChapter;
use App\Models\User;
use App\Services\RoutineService;
use App\Services\Teaching\SyllabusService;
use Illuminate\Support\Collection;

/** A teacher, a class with its routine and a syllabus: what most Teaching Plan tests need. */
trait MakesTeachingData
{
    use MakesSchoolData;

    protected User $admin;
    protected User $teacher;
    protected User $other;
    protected User $head;
    protected SchoolClass $class;
    protected Subject $math;

    protected function setUpTeaching(): void
    {
        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->teacher = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Karim Sir']);
        $this->other = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Salma Madam']);
        $this->head = $this->makeUser('head_teacher', ['name' => 'Head Teacher']);

        foreach ([['1st', '10:00', '10:40'], ['2nd', '10:40', '11:20'], ['3rd', '11:20', '12:00']] as $i => $p) {
            RoutinePeriod::firstOrCreate(['shift_id' => null, 'class_id' => null, 'name' => $p[0]], ['starts_at' => $p[1], 'ends_at' => $p[2], 'sort_order' => $i + 1]);
        }

        $this->class = $this->makeClass('Class 6', 'A', 2026, ['subjects' => ['Mathematics', 'English']]);
        $this->class->assignSubjectTeacher('Mathematics', $this->teacher);
        $this->class->assignSubjectTeacher('English', $this->other);
        $this->math = Subject::where('name', 'Mathematics')->firstOrFail();
    }

    /** place the subject in the routine on these days (0 = Sunday), first period */
    protected function routineOn(SchoolClass $class, string $subject, User $teacher, array $days = [0, 1, 2, 3, 4], int $period = 0): void
    {
        $svc = app(RoutineService::class);
        $p = $svc->periodsForClass($class)->where('is_break', false)->values()[$period];

        foreach ($days as $d) {
            $svc->setCell($class, $d, $p->id, $subject, null, $teacher->id);
        }
    }

    /** @return Collection<int,SyllabusChapter> three chapters; the first has two topics */
    protected function syllabus(string $class = 'Class 6', ?Subject $subject = null, string $year = '2026'): Collection
    {
        $subject ??= $this->math;
        $svc = app(SyllabusService::class);
        $svc->addLines($year, $class, $subject->id, "Numbers | 10\n- Natural numbers\n- Fractions\nAlgebra | 10\nGeometry | 20");

        return $svc->chapters($year, $class, $subject->id);
    }
}
