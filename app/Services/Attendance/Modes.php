<?php

namespace App\Services\Attendance;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Services\AcademicCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily or period-wise, per date. A class's own setting wins over the school default; both are kept with the date they
 * start from, so an earlier date always answers the way it did then. Changes start today or later and never over days
 * that already have attendance in the other mode.
 */
class Modes
{
    public const MODES = ['daily', 'period'];

    /** @var array<int,array<int,object>>|null */
    private ?array $rows = null;

    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];

            foreach (DB::table('attendance_modes')->orderBy('effective_from')->get() as $r) {
                $this->rows[(int) $r->scope][] = $r;
            }
        }

        return $this->rows;
    }

    /** the entry of a scope in force on a date */
    private function entry(int $scope, string $date): ?object
    {
        $found = null;

        foreach ($this->rows()[$scope] ?? [] as $r) {
            if (substr($r->effective_from, 0, 10) > $date) {
                break;
            }

            $found = $r;
        }

        return $found;
    }

    public function school(string $date): string
    {
        return $this->entry(0, $date)?->mode ?? 'daily';
    }

    public function for(int $classId, string $date): string
    {
        return $this->entry($classId, $date)?->mode ?? $this->school($date);
    }

    /** one line per class for the settings table */
    public function table(iterable $classes, ?string $today = null): array
    {
        $today ??= AcademicCalendar::today();
        $out = [];

        foreach ($classes as $c) {
            $own = $this->entry($c->id, $today);
            $source = $own?->mode ? $own : $this->entry(0, $today);
            $next = collect($this->rows()[$c->id] ?? [])->first(fn ($r) => substr($r->effective_from, 0, 10) > $today);
            $out[] = [
                'class_id' => $c->id, 'mode' => $this->for($c->id, $today), 'own' => $own?->mode,
                'from' => $source ? substr($source->effective_from, 0, 10) : null,
                'next' => $next ? ['id' => $next->id, 'mode' => $next->mode, 'from' => substr($next->effective_from, 0, 10)] : null,
            ];
        }

        return $out;
    }

    /** every change, newest first */
    public function history(): array
    {
        return DB::table('attendance_modes as m')->leftJoin('users as u', 'u.id', '=', 'm.created_by')->leftJoin('classes as c', 'c.id', '=', 'm.scope')
            ->orderByDesc('m.effective_from')->orderByDesc('m.id')->limit(100)
            ->get(['m.id', 'm.scope', 'm.mode', 'm.effective_from', 'm.created_at', 'u.name as by', 'c.class_name', 'c.section'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** what a change would touch, said before it is saved */
    public function impact(int $scope, ?string $mode, string $from): array
    {
        $new = $mode ?? $this->school($from);
        $classes = $scope ? [$scope] : SchoolClass::where('status', 'active')->pluck('id')->filter(fn ($id) => $this->entry($id, $from)?->mode === null)->values()->all();
        $changing = array_values(array_filter($classes, fn ($id) => $this->for($id, $from) !== $new));
        $conflict = $changing ? DB::table('attendance_sessions')->whereIn('class_id', $changing)->where('date', '>=', $from)->where('mode', '!=', $new)->max('date') : null;

        return [
            'classes' => count($changing),
            'past_sessions' => $changing ? DB::table('attendance_sessions')->whereIn('class_id', $changing)->where('date', '<', $from)->count() : 0,
            'conflict_until' => $conflict ? substr($conflict, 0, 10) : null,
        ];
    }

    public function save(int $scope, ?string $mode, string $from, ?int $userId): void
    {
        if ($from < AcademicCalendar::today()) {
            $this->fail('past_mode');
        }

        if ($scope === 0 && $mode === null) {
            $this->fail('school_needs_mode');
        }

        $impact = $this->impact($scope, $mode, $from);

        if ($impact['conflict_until']) {
            $this->fail('mode_conflict', ['date' => $impact['conflict_until']]);
        }

        DB::transaction(function () use ($scope, $mode, $from, $userId, $impact) {
            // one row per scope and date, so two settings can never overlap
            DB::table('attendance_modes')->updateOrInsert(['scope' => $scope, 'effective_from' => $from],
                ['mode' => $mode, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('attendance_modes')->where(['scope' => $scope, 'effective_from' => $from])->value('id');
            $this->log($id, 'attendance.mode', ['scope' => $scope, 'mode' => $mode, 'from' => $from, 'classes' => $impact['classes']], $userId);
        });

        $this->rows = null;
    }

    /** remove a change that has not started yet */
    public function cancel(int $id, ?int $userId): void
    {
        $row = DB::table('attendance_modes')->find($id) ?? abort(404);
        $from = substr($row->effective_from, 0, 10);

        if ($from <= AcademicCalendar::today() || DB::table('attendance_sessions')->where('date', '>=', $from)->when($row->scope, fn ($q) => $q->where('class_id', $row->scope))->exists()) {
            $this->fail('mode_started');
        }

        DB::table('attendance_modes')->where('id', $id)->delete();
        $this->log($id, 'attendance.mode_cancelled', ['scope' => $row->scope, 'mode' => $row->mode, 'from' => $from], $userId);
        $this->rows = null;
    }

    private function log(int $id, string $event, array $changes, ?int $userId): void
    {
        AuditLog::create(['user_id' => $userId, 'event' => $event, 'auditable_type' => 'attendance_mode', 'auditable_id' => $id, 'label' => 'Attendance mode', 'changes' => $changes, 'ip' => request()?->ip(), 'created_at' => now()]);
    }

    private function fail(string $key, array $p = []): never
    {
        throw ValidationException::withMessages(['attendance' => __("attendance.err.{$key}", $p)]);
    }
}
