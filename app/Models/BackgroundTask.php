<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/** A long-running job with progress the screen can poll: queued → running → done | failed. */
class BackgroundTask extends Model
{
    protected $fillable = ['type', 'user_id', 'status', 'total', 'processed', 'payload', 'summary', 'error', 'started_at', 'finished_at'];

    protected $casts = ['payload' => 'array', 'summary' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function queue(string $type, ?User $by, array $payload = [], int $total = 0): self
    {
        return static::create(['type' => $type, 'user_id' => $by?->id, 'payload' => $payload, 'total' => $total]);
    }

    public function start(?int $total = null): void
    {
        $this->update(['status' => 'running', 'started_at' => now(), 'total' => $total ?? $this->total]);
    }

    /** Count work done; written to the database every few items so polling stays cheap. */
    public function advance(int $by = 1): void
    {
        $before = $this->processed;
        $this->processed += $by;

        if ($this->processed >= $this->total || intdiv($this->processed, 10) > intdiv($before, 10)) {
            $this->save();
        }
    }

    public function finish(array $summary = []): void
    {
        $this->update(['status' => 'done', 'processed' => max($this->processed, $this->total), 'summary' => $summary, 'finished_at' => now()]);
    }

    public function fail(Throwable $e): void
    {
        // a bad upload (a corrupt ZIP, say) is the user's problem, not a bug: log it quietly instead of reporting it
        \Illuminate\Support\Facades\Log::warning("Background task {$this->id} ({$this->type}) failed: " . $e->getMessage());

        // the message is kept for the user
        $this->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
    }

    public function getPercentAttribute(): int
    {
        return $this->total > 0 ? min(100, (int) floor($this->processed / $this->total * 100)) : ($this->status === 'done' ? 100 : 0);
    }

    public function toProgress(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'status' => $this->status, 'total' => $this->total,
            'processed' => $this->processed, 'percent' => $this->percent, 'summary' => $this->summary, 'error' => $this->error,
        ];
    }
}
