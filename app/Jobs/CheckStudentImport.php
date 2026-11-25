<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\BulkStudentImport;
use App\Support\SheetStream;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The preview of an upload, run in the background so a file of any size can be checked: the whole file is streamed
 * through the same checks the import uses, nothing is saved, and the user then sees the counts, the first rows to fix
 * and a full "rows to fix" CSV.
 */
class CheckStudentImport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 7200;

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
            $by = User::find($task->user_id) ?? throw new RuntimeException('The user who uploaded this file no longer exists.');
            $path = $disk->path($import->path);

            $task->start(SheetStream::estimateRows($path) ?? 0);
            $done = 0;

            $result = $bulk->run($path, $by, $import->session, false, $disk->path("student-imports/{$import->id}-errors.csv"), function (int $rows) use ($task, &$done) {
                $task->advance($rows - $done);
                $done = $rows;
            });

            $import->update([
                'status' => 'previewed', 'total_rows' => $result['total'], 'valid_rows' => $result['valid'], 'invalid_rows' => $result['invalid'], 'invalid' => $result['sample'],
            ]);
            $task->finish(['total' => $result['total']]);
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'The file could not be checked. Check that it is a valid .xlsx or .csv file.';

            if (! $e instanceof RuntimeException) {
                Log::error("Import check {$import->id} failed: " . $e->getMessage());
            }

            $task->fail(new RuntimeException($message));
            $import->update(['status' => 'failed', 'summary' => ['error' => $message]]);

            if ($import->path) {
                $disk->delete($import->path);
                $import->update(['path' => null]);
            }
        }
    }
}
