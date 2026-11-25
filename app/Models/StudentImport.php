<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An uploaded student file: checked into a preview first, saved only after the user confirms. */
class StudentImport extends Model
{
    protected $fillable = ['user_id', 'original_name', 'path', 'session', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'invalid', 'summary', 'task_id'];

    protected $casts = ['invalid' => 'array', 'summary' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(BackgroundTask::class, 'task_id');
    }
}
