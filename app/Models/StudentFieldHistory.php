<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One changed personal field: what it was, what it became, who did it, and from which door. */
class StudentFieldHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['profile_id', 'field', 'old_value', 'new_value', 'changed_by', 'source', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
