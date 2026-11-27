<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller as Base;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AcademicCalendar;
use App\Services\Attendance\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

abstract class Controller extends Base
{
    /** the signed-in user, who must hold one of these abilities in some scope */
    protected function need(Request $request, string ...$abilities): User
    {
        $user = $request->user();
        abort_unless(collect($abilities)->contains(fn ($a) => $user->canDo($a)), 403);

        return $user;
    }

    protected function year(Request $request): string
    {
        $year = (string) $request->input('year', '');

        return preg_match('/^\d{4}$/', $year) ? $year : substr(AcademicCalendar::today(), 0, 4);
    }

    /** @return array<int,string> */
    protected function years(): array
    {
        $years = SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all();

        return $years ?: [substr(AcademicCalendar::today(), 0, 4)];
    }

    /** the classes of a year the user has the ability for, in school order */
    protected function classes(User $user, string $ability, string $year): Collection
    {
        $ids = Access::classIds($user, $ability);

        return SchoolClass::query()->where('academic_year', $year)->where('status', 'active')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('class_name')->orderBy('section')->get();
    }

    protected function options(Collection $classes): array
    {
        $reports = app(\App\Services\Attendance\Reports::class);

        return $classes->map(fn ($c) => ['id' => $c->id, 'class_name' => $c->class_name, 'label' => $reports->label($c),
            'teacher' => $c->class_teacher, 'shift_id' => $c->shift_id, 'group_id' => $c->study_group_id])->values()->all();
    }

    protected function date(Request $request, string $key = 'date', ?string $default = null): string
    {
        $v = (string) $request->input($key, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : ($default ?? AcademicCalendar::today());
    }
}
