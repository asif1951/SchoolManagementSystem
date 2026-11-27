<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every "may this person …" of attendance. "Own" classes are those the user is class teacher of, plus those where they
 * teach a subject. A subject teacher is limited to their own subjects' periods; daily attendance belongs to the class
 * teacher.
 */
class Access
{
    /** @return array<int,int>|null the classes the ability covers: null = every class */
    public static function classIds(User $u, string $ability): ?array
    {
        $granted = $u->grantedAbilities();

        if (in_array('*', $granted, true) || in_array($ability, $granted, true)) {
            return null;
        }

        if (! in_array("{$ability}:own", $granted, true)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_merge($u->ownedClassIds(), array_keys($u->taughtSubjects())))));
    }

    public static function can(User $u, string $ability, int $classId): bool
    {
        $ids = self::classIds($u, $ability);

        return $ids === null || in_array($classId, $ids, true);
    }

    /** a whole-school holder of the ability, or the class teacher holding the ":own" form */
    public static function wholeClass(User $u, string $ability, int $classId): bool
    {
        $granted = $u->grantedAbilities();

        return in_array('*', $granted, true) || in_array($ability, $granted, true)
            || (in_array("{$ability}:own", $granted, true) && in_array($classId, array_map('intval', $u->ownedClassIds()), true));
    }

    /** @return array<int,int>|null the subjects a subject teacher may see in this class: null = everything */
    public static function subjectLimit(User $u, string $ability, int $classId): ?array
    {
        if (self::wholeClass($u, $ability, $classId)) {
            return null;
        }

        $names = $u->taughtSubjects()[$classId] ?? [];

        return $names ? DB::table('subjects')->whereIn('name', $names)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
    }

    public static function takeDaily(User $u, int $classId, string $ability = 'attendance.take'): bool
    {
        return self::wholeClass($u, $ability, $classId);
    }

    /** @param  array{teacher_id:?int,period_id:?int}  $slot */
    public static function takePeriod(User $u, int $classId, array $slot, string $date, string $ability = 'attendance.take'): bool
    {
        if (self::wholeClass($u, $ability, $classId)) {
            return true;
        }

        if (! $u->canDo($ability)) {
            return false;
        }

        // a substitute named in the day's teaching log
        if (! empty($slot['period_id']) && DB::table('teaching_logs')->where(['log_date' => $date, 'class_id' => $classId, 'period_id' => $slot['period_id'], 'substitute_id' => $u->id])->exists()) {
            return true;
        }

        // their own period, or (when the school allows it) any teacher of the class standing in
        return self::can($u, $ability, $classId) && ((int) ($slot['teacher_id'] ?? 0) === $u->id || Settings::get('allow_substitute'));
    }
}
