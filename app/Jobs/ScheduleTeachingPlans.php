<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\Teaching\TeachingPlan;
use App\Services\Teaching\Scheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Auto-schedules many undated plans, with progress. (One plan is scheduled at once, without a job.) */
class ScheduleTeachingPlans implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(public int $taskId)
    {
    }

    public function handle(Scheduler $scheduler): void
    {
        $task = BackgroundTask::findOrFail($this->taskId);

        try {
            Auth::onceUsingId($task->user_id);
            $ids = $task->payload['plans'];
            $task->start(count($ids));
            $done = 0;
            $errors = [];

            foreach (array_chunk($ids, 100) as $chunk) {
                foreach (TeachingPlan::whereIn('id', $chunk)->get() as $plan) {
                    try {
                        $scheduler->auto($plan, $task->payload['from'] ?? null);
                        $done++;
                    } catch (ValidationException $e) {
                        count($errors) < 100 && $errors[] = ['row' => "Plan {$plan->id}", 'reason' => collect($e->errors())->flatten()->first()];
                    }

                    $task->advance();
                }
            }

            $task->finish(['scheduled' => $done, 'errors' => $errors]);
        } catch (Throwable $e) {
            Log::error("Scheduling task {$task->id} failed: " . $e->getMessage());
            $task->fail(new \RuntimeException('Scheduling stopped because of an unexpected error.'));
        }
    }
}
