<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The routine of one class row. `slots` is the draft being edited; `published_slots` is what was published. */
class ClassRoutine extends Model
{
    protected $fillable = ['class_id', 'published_at', 'published_by', 'published_slots', 'notes'];

    protected $casts = ['published_at' => 'datetime', 'published_slots' => 'array'];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function slots()
    {
        return $this->hasMany(ClassRoutineSlot::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->published_at !== null;
    }
}
