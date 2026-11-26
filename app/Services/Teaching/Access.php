<?php

namespace App\Services\Teaching;

use App\Models\Teaching\TeachingPlan;
use App\Models\User;

/** Every "may this person …" of the module, in one place, so a screen and its endpoint cannot disagree. */
class Access
{
    public const ABILITIES = ['teaching.own', 'teaching.view_all', 'teaching.manage_all', 'teaching.approve', 'teaching.review', 'teaching.edit_past', 'teaching.syllabus', 'teaching.settings'];

    /** may see other teachers' records */
    public static function seesAll(User $u): bool
    {
        return $u->canDo('teaching.view_all') || $u->canDo('teaching.manage_all') || $u->canDo('teaching.approve') || $u->canDo('teaching.review');
    }

    public static function uses(User $u): bool
    {
        return $u->canDo('teaching.own') || self::seesAll($u);
    }

    public static function viewPlan(User $u, TeachingPlan $p): bool
    {
        return self::seesAll($u) || ($u->canDo('teaching.own') && $p->teacher_id === $u->id);
    }

    public static function editPlan(User $u, TeachingPlan $p): bool
    {
        return self::writeFor($u, $p->teacher_id);
    }

    /** may write the plans and daily log of this teacher */
    public static function writeFor(User $u, int $teacherId): bool
    {
        return $u->canDo('teaching.manage_all') || ($u->canDo('teaching.own') && $teacherId === $u->id);
    }

    /** the teacher id a list must be limited to: null = everyone */
    public static function limitTo(User $u): ?int
    {
        return self::seesAll($u) ? null : $u->id;
    }
}
