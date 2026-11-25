<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Put `use Audited;` on a model and every create, change and delete of it is written to the
 * audit log with the user who did it. Changes store the old and new value of each field.
 *
 * Only changes made through the model are recorded (not raw query-builder writes).
 */
trait Audited
{
    protected static function bootAudited(): void
    {
        static::created(function ($model) {
            AuditLog::record($model, 'created', $model->auditSnapshot());
        });

        static::updated(function ($model) {
            $changes = [];

            foreach ($model->getChanges() as $field => $new) {
                if ($field === 'updated_at' || in_array($field, $model->auditIgnore(), true)) {
                    continue;
                }

                $changes[$field] = ['from' => $model->getOriginal($field), 'to' => $new];
            }

            if ($changes) {
                AuditLog::record($model, 'updated', $changes);
            }
        });

        static::deleted(function ($model) {
            AuditLog::record($model, 'deleted', $model->auditSnapshot());
        });
    }

    /** Fields that are never worth a log line. */
    public function auditIgnore(): array
    {
        return ['public_token'];
    }

    protected function auditSnapshot(): array
    {
        return array_diff_key($this->getAttributes(), array_flip(['created_at', 'updated_at', ...$this->auditIgnore()]));
    }
}
