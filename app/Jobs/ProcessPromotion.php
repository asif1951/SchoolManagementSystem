<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentPromotion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Runs a confirmed automatic promotion. The plan is built again at this moment (students may have changed since
 * the preview) and carried out in a single transaction: either every student is promoted or none is.
 */
class ProcessPromotion implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public int $taskId)
    {
    }

    public function handle(StudentPromotion $promotion): void
    {
        @ini_set('memory_limit', '1024M');

        $task = BackgroundTask::findOrFail($this->taskId);
        $p = $task->payload;

        try {
            $by = User::findOrFail($task->user_id);

            // progress is counted in students: everyone enrolled in the source classes
            $total = Student::query()->join('classes', 'classes.id', '=', 'students.class_id')->where('classes.academic_year', $p['from'])->where('classes.status', 'active')
                ->when($p['class_name'] ?? null, fn ($q, $n) => $q->where('classes.class_name', $n))->count();

            $task->start(max($total, 1));

            $plan = ['from' => $p['from'], 'to' => $p['to'], 'scope' => $p['class_name'] ?? null, 'options' => $p['options'] ?? []];
            $run = $promotion->execute($plan, $by, 'auto', $task->id, fn (int $n) => $task->advance($n));

            $task->processed = $task->total;
            $task->finish([
                'run_id' => $run->id, 'promoted' => $run->promoted, 'graduated' => $run->graduated, 'skipped' => $run->skipped,
                'blocked' => (int) $run->getAttribute('blocked'),
            ]);
        } catch (Throwable $e) {
            // the transaction rolled back: nobody was moved. A message meant for the user is kept, anything else is generic.
            $task->fail($e instanceof \Illuminate\Validation\ValidationException ? new \RuntimeException(collect($e->errors())->flatten()->implode(' ')) : $e);
        }
    }
}
