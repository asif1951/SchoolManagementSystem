<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Models\BackgroundTask;
use App\Support\Normalize;
use Illuminate\Http\Request;

/** Progress of a background task (polled by the screen) and the downloadable report of what was refused. */
class TaskController extends Controller
{
    public function show(Request $request, BackgroundTask $task)
    {
        $this->authorizeTask($request, $task);

        return response()->json($task->toProgress());
    }

    /** CSV of the rows or files that were not processed, with the reason; safe to open in Excel. */
    public function report(Request $request, BackgroundTask $task)
    {
        $this->authorizeTask($request, $task);

        // a long list of refused rows is kept as a file (an import of a million rows can refuse thousands)
        if (($file = $task->summary['errors_file'] ?? null) && \Illuminate\Support\Facades\Storage::disk('local')->exists($file)) {
            return \Illuminate\Support\Facades\Storage::disk('local')->download($file, "task-{$task->id}-not-processed.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $rows = $task->summary['unmatched'] ?? $task->summary['errors'] ?? [];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Row / file', 'Reason']);

            foreach ($rows as $r) {
                fputcsv($out, [Normalize::csvCell($r['file'] ?? ($r['row'] ?? '')), Normalize::csvCell($r['reason'] ?? '')]);
            }

            fclose($out);
        }, "task-{$task->id}-not-processed.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** the status page of a background export: progress, then the download */
    public function export(Request $request, BackgroundTask $task)
    {
        $this->authorizeTask($request, $task);
        abort_unless($task->type === 'export', 404);

        return \Inertia\Inertia::render('StudentModule/ExportStatus', ['taskId' => $task->id]);
    }

    /** the finished file of an export */
    public function download(Request $request, BackgroundTask $task)
    {
        $this->authorizeTask($request, $task);

        $file = $task->summary['file'] ?? null;
        $disk = \Illuminate\Support\Facades\Storage::disk('local');

        abort_unless($task->type === 'export' && $task->status === 'done' && $file && $disk->exists($file), 404);

        return $disk->download($file, $task->summary['name'] ?? basename($file), ['X-Content-Type-Options' => 'nosniff']);
    }

    /** a task belongs to the person who started it (and to whoever manages users) */
    private function authorizeTask(Request $request, BackgroundTask $task): void
    {
        abort_unless($task->user_id === $request->user()->id || $request->user()->canDo('users.manage'), 403);
    }
}
