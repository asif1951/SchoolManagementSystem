<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Services\Teaching\Reports;
use App\Support\SheetStream;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Writes a Teaching Plan report to an Excel file, with progress. Formulas are neutralised by the writer. */
class ExportTeachingReport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(public int $taskId)
    {
    }

    public function handle(Reports $reports): void
    {
        $task = BackgroundTask::findOrFail($this->taskId);
        $p = $task->payload;

        try {
            app()->setLocale($p['locale'] ?? 'en');
            $disk = Storage::disk('local');
            $disk->makeDirectory('exports');
            $file = "exports/{$task->id}.xlsx";
            $task->start(0);
            $rows = SheetStream::writeXlsx($reports->stream($p['report'], $p['filters'], function (int $n) use ($task) {
                $task->update(['processed' => $task->processed + $n, 'total' => $task->processed + $n]);
            }), $disk->path($file), 'Report');
            $task->finish(['file' => $file, 'name' => "teaching-{$p['report']}.xlsx", 'rows' => $rows]);
        } catch (Throwable $e) {
            Log::error("Teaching report export {$task->id} failed: " . $e->getMessage());
            $task->fail(new RuntimeException('The export stopped because of an unexpected error.'));
        }
    }
}
