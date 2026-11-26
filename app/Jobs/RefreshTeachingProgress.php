<?php

namespace App\Jobs;

use App\Services\Teaching\Progress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Once a day "expected by today" moves for every plan: this brings a whole session up to date away from any screen. */
class RefreshTeachingProgress implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public string $year)
    {
    }

    public function handle(Progress $progress): void
    {
        $progress->refreshStale($this->year);
    }
}
