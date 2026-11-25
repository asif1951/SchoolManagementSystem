<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\BackgroundTask;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\BulkStudentImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Saves a confirmed import. The file is streamed and checked again first (the students may have changed since the
 * preview), and every chunk of 2,000 valid rows is saved in one transaction with bulk inserts (see BulkStudentImport).
 * The rows that were refused go to a CSV the user can download; the upload itself is not kept.
 */
class ProcessStudentImport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 14400;

    public function __construct(public int $taskId)
    {
    }

    public function handle(BulkStudentImport $bulk): void
    {
        @ini_set('memory_limit', '1024M');

        $task = BackgroundTask::findOrFail($this->taskId);
        $import = StudentImport::findOrFail($task->payload['import_id']);
        $disk = Storage::disk('local');

        try {
            $by = User::find($task->user_id) ?? throw new RuntimeException('The user who started this import no longer exists.');

            $task->start(max($import->total_rows, 1));
            $done = 0;
            $report = "student-imports/{$import->id}-result.csv";

            $r = $bulk->run($disk->path($import->path), $by, $import->session, true, $disk->path($report), function (int $rows) use ($task, &$done) {
                $task->advance($rows - $done);
                $done = $rows;
            });

            $summary = ['created' => $r['created'], 'failed_count' => $r['invalid'], 'errors' => $r['sample'], 'errors_file' => $r['invalid'] ? $report : null];

            AuditLog::record($import, 'bulk_import', ['file' => $import->original_name, 'rows' => $r['total'], 'created' => $r['created'], 'refused' => $r['invalid']], $by->id);

            $task->finish($summary);
            $import->update(['status' => 'done', 'summary' => ['created' => $r['created'], 'failed_count' => $r['invalid']]]);
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'The import stopped because of an unexpected error. Nothing in the failed block was saved; check the log.';

            if (! $e instanceof RuntimeException) {
                Log::error("Import {$import->id} failed: " . $e->getMessage());
            }

            $task->fail(new RuntimeException($message));
            $import->update(['status' => 'failed', 'summary' => ['error' => $message]]);
        } finally {
            if ($import->path) {
                $disk->delete($import->path);                           // the upload is not kept
                $import->update(['path' => null]);
            }
        }
    }
}
