<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\AdmissionPayments;
use App\Services\StudentReports;
use App\Support\SheetStream;
use Generator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Writes a large export to a file in the background, streaming: rows come out of the database a page at a time and go
 * straight into the file, so a million students need a few megabytes. The user gets a progress bar and a download.
 *
 * payload: kind = students | dues, format = xlsx | csv, plus what the kind needs (filters, columns / session, class).
 */
class RunExport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 14400;

    public function __construct(public int $taskId)
    {
    }

    /** the rows of an export: the heading first, then one array per row (also used for exports small enough to run at once) */
    public static function rows(array $payload, User $user, ?callable $tick = null): Generator
    {
        if (($payload['kind'] ?? '') === 'dues') {
            $n = 0;

            foreach (app(AdmissionPayments::class)->duesRows((string) $payload['session'], $payload['class_name'] ?? null, $payload['to'] ?? null) as $row) {
                yield $row;
                $tick && $n++ % 5000 === 4999 && $tick(5000);
            }

            return;
        }

        $reports = app(StudentReports::class);
        $labels = collect(StudentReports::COLUMNS)->map(fn ($c) => $c[0])->all();
        $columns = $payload['columns'];
        $full = $user->canDo('students.view_full_brn');          // asked once, not once per row

        yield array_map(fn ($k) => $labels[$k], $columns);

        $n = 0;

        foreach ($reports->stream($payload['filters'], $user) as $s) {
            yield array_values($reports->row($s, $columns, $user, $full));
            $tick && $n++ % 5000 === 4999 && $tick(5000);
        }
    }

    /** write the rows to $path in the format; returns the number of data rows */
    public static function write(iterable $rows, string $format, string $path, string $sheet): int
    {
        return $format === 'csv' ? SheetStream::writeCsv($rows, $path) : SheetStream::writeXlsx($rows, $path, $sheet);
    }

    public function handle(): void
    {
        @ini_set('memory_limit', '1024M');

        $task = BackgroundTask::findOrFail($this->taskId);
        $payload = $task->payload;
        $disk = Storage::disk('local');

        try {
            $user = User::find($task->user_id) ?? throw new RuntimeException('The user who asked for this export no longer exists.');
            $format = ($payload['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
            $file = "exports/{$task->id}.{$format}";

            $disk->makeDirectory('exports');

            // the exact size, for the progress bar (the screen could only afford a capped count)
            $total = ($payload['kind'] ?? '') === 'students' ? app(StudentReports::class)->exact($payload['filters'], $user) : $task->total;
            $task->start($total);

            $rows = self::write(self::rows($payload, $user, fn (int $n) => $task->advance($n)), $format, $disk->path($file), $payload['sheet'] ?? 'Students');

            $task->finish(['file' => $file, 'name' => $payload['name'] ?? "export-{$task->id}.{$format}", 'rows' => $rows]);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                Log::error("Export {$task->id} failed: " . $e->getMessage());
            }

            $task->fail(new RuntimeException($e instanceof RuntimeException ? $e->getMessage() : 'The export stopped because of an unexpected error.'));
        }
    }
}
