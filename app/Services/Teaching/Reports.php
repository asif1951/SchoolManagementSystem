<?php

namespace App\Services\Teaching;

use App\Models\User;
use Generator;
use Illuminate\Support\Facades\DB;

/** The five reports. Each is one list of columns and one source of rows, used by the screen, the print and the Excel file. */
class Reports
{
    public const KEYS = ['progress', 'pending', 'delayed', 'completion', 'activity'];

    /** the columns of each report: key => translation key of its heading */
    private const COLUMNS = [
        'progress' => ['teacher' => 'teacher', 'class' => 'class', 'subject' => 'subject', 'chapters' => 'chapters', 'done' => 'done', 'expected' => 'expected', 'gap' => 'report.gap', 'health' => 'status_label', 'delayed' => 'status.delayed', 'finish' => 'finish_by'],
        'pending' => ['date' => 'date', 'teacher' => 'teacher', 'missing' => 'report.missing', 'due' => 'report.due'],
        'delayed' => ['teacher' => 'teacher', 'class' => 'class', 'subject' => 'subject', 'chapter' => 'chapter', 'due' => 'report.due_date', 'days' => 'report.days_late', 'progress' => 'done'],
        'completion' => ['class' => 'class', 'subject' => 'subject', 'teacher' => 'teacher', 'chapters' => 'chapters', 'completed' => 'status.completed', 'done' => 'done'],
        'activity' => ['teacher' => 'teacher', 'entries' => 'report.entries', 'held' => 'report.held', 'not_held' => 'log.not_held', 'extra' => 'log.extra', 'substituted' => 'report.substituted', 'homework' => 'log.homework', 'revision' => 'understanding.revision', 'last_on' => 'report.last_entry'],
    ];

    /** reports that are about a range of dates */
    public const DATED = ['pending', 'activity'];

    public function __construct(private Insights $insights, private Rows $rows)
    {
    }

    /** @return array<string,string> column key => heading */
    public function headings(string $report): array
    {
        return array_map(fn ($k) => __("teaching.{$k}"), self::COLUMNS[$report]);
    }

    /** the filters a user may use: someone who sees only their own records is held to them */
    public function filters(array $in, User $user, string $year): array
    {
        $today = Calendar::today();
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $to = min($date($in['to'] ?? null) ?? $today, $today);
        $from = $date($in['from'] ?? null) ?? date('Y-m-d', strtotime($to . ' -6 days'));
        // at most a year at a time
        $from = max($from, date('Y-m-d', strtotime($to . ' -366 days')));

        return ['session' => $year, 'from' => min($from, $to), 'to' => $to,
            'teacher' => Access::limitTo($user) ?? ((int) ($in['teacher'] ?? 0) ?: null),
            'class' => (int) ($in['class'] ?? 0) ?: null, 'subject' => (int) ($in['subject'] ?? 0) ?: null, 'health' => ($in['health'] ?? null) ?: null];
    }

    /**
     * One page of a report (or every row when $perPage is 0), as arrays keyed by column.
     *
     * @return array{rows:array, more:bool}
     */
    public function page(string $report, array $f, int $page = 1, int $perPage = 50): array
    {
        if ($report === 'pending') {
            $all = $this->pendingRows($f);
            $rows = $perPage ? array_slice($all, ($page - 1) * $perPage, $perPage) : $all;

            return ['rows' => $rows, 'more' => $perPage && count($all) > $page * $perPage];
        }

        $query = $this->query($report, $f);
        $found = $perPage ? $query->offset(($page - 1) * $perPage)->limit($perPage + 1)->get() : $query->get();
        $more = $perPage && $found->count() > $perPage;

        return ['rows' => $this->present($report, ($more ? $found->take($perPage) : $found)), 'more' => (bool) $more];
    }

    /** every row for a file, read a block at a time */
    public function stream(string $report, array $f, ?callable $tick = null): Generator
    {
        yield array_values($this->headings($report));
        $keys = array_keys(self::COLUMNS[$report]);

        for ($page = 1;; $page++) {
            $r = $this->page($report, $f, $page, 1000);

            foreach ($r['rows'] as $row) {
                yield array_map(fn ($k) => $row[$k] ?? '', $keys);
            }

            $tick && $tick(count($r['rows']));

            if (! $r['more']) {
                break;
            }
        }
    }

    private function query(string $report, array $f)
    {
        return match ($report) {
            'progress' => $this->rows->plans($f['session'], $f)->orderBy('p.gap')->orderBy('p.id'),
            'delayed' => $this->insights->delayedQuery($f['session'], $f),
            'activity' => $this->insights->activity($f['from'], $f['to'], $f['teacher']),
            'completion' => $this->rows->plans($f['session'], $f)
                ->addSelect(DB::raw('(SELECT COUNT(*) FROM teaching_plan_items i WHERE i.plan_id = p.id AND i.progress >= 100) as completed'))
                ->orderBy('c.class_name')->orderBy('c.section')->orderBy('s.name')->orderBy('p.id'),
        };
    }

    private function present(string $report, $found): array
    {
        $health = fn (string $h) => __("teaching.status.{$h}");

        return match ($report) {
            'progress' => array_map(fn ($r) => ['health' => $health($r['health'])] + $r, $this->rows->present($found->all())),
            'completion' => $found->map(fn ($r) => ['class' => $this->rows->label($r), 'subject' => $r->subject, 'teacher' => $r->teacher, 'chapters' => (int) $r->chapters, 'completed' => (int) $r->completed, 'done' => (float) $r->done_pct])->all(),
            'delayed' => $this->insights->presentDelayed($found),
            'activity' => $found->map(fn ($r) => ['teacher' => $r->teacher, 'entries' => (int) $r->entries, 'held' => (int) $r->held, 'not_held' => (int) $r->not_held, 'extra' => (int) $r->extra,
                'substituted' => (int) $r->substituted, 'homework' => (int) $r->homework, 'revision' => (int) $r->revision, 'last_on' => substr((string) $r->last_on, 0, 10)])->all(),
        };
    }

    private function pendingRows(array $f): array
    {
        $rows = $this->insights->pending($f['from'], $f['to'], $f['teacher']);
        $names = $this->insights->names(array_column($rows, 'teacher_id'));

        return array_map(fn ($r) => ['date' => $r['date'], 'teacher' => $names[$r['teacher_id']] ?? '?', 'missing' => $r['missing'], 'due' => $r['due']], $rows);
    }
}
