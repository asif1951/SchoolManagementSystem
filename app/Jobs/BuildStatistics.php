<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\StudentReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Builds the Student Statistics of a large school in the background (about half a minute at a million students) and caches them. */
class BuildStatistics implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public string $session, public int $userId)
    {
    }

    public function handle(StudentReports $reports): void
    {
        try {
            if ($user = User::find($this->userId)) {
                $reports->rebuild($this->session, $user);
            }
        } catch (Throwable $e) {
            Log::error("Statistics for {$this->session} failed: " . $e->getMessage());
        } finally {
            $reports->unlock($this->session, User::find($this->userId));
        }
    }
}
