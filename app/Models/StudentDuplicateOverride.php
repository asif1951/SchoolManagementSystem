<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who saved a likely duplicate anyway, which record it looked like, and the reason they gave. */
class StudentDuplicateOverride extends Model
{
    public $timestamps = false;

    protected $fillable = ['profile_id', 'matched_profile_id', 'context', 'reason', 'score', 'user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id');
    }

    public function matched()
    {
        return $this->belongsTo(StudentProfile::class, 'matched_profile_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
