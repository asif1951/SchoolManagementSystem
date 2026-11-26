<?php

namespace App\Models\Teaching;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TeachingReview extends Model
{
    protected $fillable = ['plan_id', 'user_id', 'kind', 'body'];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
