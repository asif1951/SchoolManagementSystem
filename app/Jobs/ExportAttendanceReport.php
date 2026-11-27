<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Attendance\Reports;
use App\Support\SheetStream;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Writes a large attendance report to Excel or CSV. The report is built with the permissions of whoever asked. */
class ExportAttendanceReport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(public int $taskId)
    {
    }

    /** the report as a sheet: a heading row, then the rows (formulas are neutralised by the writer) */
    public static function write(array $report, string $format, string $path): int
    {
        $rows = (function () use ($report) {
            yield array_column($report['columns'], 'label');

            foreach ($report['rows'] as $row) {
                yield array_map(fn ($c) => match (true) {
                    $c['type'] === 'date' => (string) ($row['date_label'] ?? $row[$c['key']] ?? ''),
                    $c['type'] === 'status' && isset($row[$c['key']]) => __("attendance.status.{$row[$c['key']]}"),
                    default => (string) ($row[$c['key']] ?? ''),
                }, $report['columns']);
            }
        })();

        return $format === 'csv' ? SheetStream::writeCsv($rows, $path) : SheetStream::writeXlsx($rows, $path, 'Attendance');
    }

    public function handle(Reports $reports): void
    {
        $task = BackgroundTask::findOrFail($this->taskId);
        $p = $task->payload;

        try {
            app()->setLocale($p['locale'] ?? 'en');
            $user = User::find($task->user_id) ?? throw new RuntimeException('The user who asked for this export no longer exists.');
            $report = $reports->build($user, $p['type'], $p['filters']);
            $disk = Storage::disk('local');
            $disk->makeDirectory('exports');
            $file = "exports/{$task->id}.{$p['format']}";
            $task->start(count($report['rows']));
            self::write($report, $p['format'], $disk->path($file));
            $task->finish(['file' => $file, 'name' => "attendance-{$p['type']}.{$p['format']}", 'rows' => count($report['rows'])]);
        } catch (Throwable $e) {
            Log::error("Attendance export {$task->id} failed: " . $e->getMessage());
            $task->fail(new RuntimeException('The export stopped because of an unexpected error.'));
        }
    }
}
