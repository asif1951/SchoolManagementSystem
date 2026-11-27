<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Services\Teaching\SyllabusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Saves a checked syllabus file, with progress the screen can follow. */
class ImportSyllabus implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public int $taskId)
    {
    }

    public function handle(SyllabusService $syllabus): void
    {
        $task = BackgroundTask::findOrFail($this->taskId);
        $disk = Storage::disk('local');
        $file = $task->payload['file'];

        try {
            Auth::onceUsingId($task->user_id);          // the audit log names the person who imported
            $result = $syllabus->parse($disk->path($file));
            $task->start(count($result['rows']));
            $new = $syllabus->import($task->payload['year'], $result['rows']);
            $task->advance(count($result['rows']));
            $task->finish($new + ['skipped' => count($result['errors']), 'errors' => $result['errors']]);
            $disk->delete($file);
        } catch (ValidationException $e) {
            $disk->delete($file);
            $task->fail(new RuntimeException($e->validator->errors()->first() . ' Nothing was imported.'));
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                Log::error("Syllabus import {$task->id} failed: " . $e->getMessage());
            }

            $task->fail(new RuntimeException($e instanceof RuntimeException ? $e->getMessage() : 'The import stopped because of an unexpected error.'));
        }
    }
}
