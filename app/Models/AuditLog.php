<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the audit trail: who did what to which record, and what changed.
 * Written automatically by the Audited trait; never edited.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'event', 'auditable_type', 'auditable_id', 'label', 'changes', 'ip', 'created_at'];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Record an event for $model by the signed-in user (null for seeders, jobs and the console). */
    public static function record(Model $model, string $event, array $changes, ?int $userId = null): void
    {
        static::create([
            'user_id'        => $userId ?? auth()->id(),
            'event'          => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id'   => $model->getKey(),
            'label'          => method_exists($model, 'auditLabel') ? $model->auditLabel() : null,
            'changes'        => $changes,
            'ip'             => request()?->ip(),
            'created_at'     => now(),
        ]);
    }

    public function scopeForRecord($query, Model $model)
    {
        return $query->where('auditable_type', $model->getMorphClass())->where('auditable_id', $model->getKey());
    }
}
