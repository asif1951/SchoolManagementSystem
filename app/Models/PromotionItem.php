<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What a promotion did to one student. */
class PromotionItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['run_id', 'profile_id', 'from_enrollment_id', 'to_enrollment_id', 'action', 'reason', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function run()
    {
        return $this->belongsTo(PromotionRun::class, 'run_id');
    }

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id')->withTrashed();
    }
}
