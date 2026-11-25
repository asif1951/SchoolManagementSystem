<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One promotion (automatic or manual): who ran it, the options, the counts. */
class PromotionRun extends Model
{
    protected $fillable = ['user_id', 'mode', 'from_session', 'to_session', 'scope', 'options', 'status', 'promoted', 'repeated', 'dropped', 'transferred', 'graduated', 'skipped', 'task_id'];

    protected $casts = ['options' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(PromotionItem::class, 'run_id');
    }
}
