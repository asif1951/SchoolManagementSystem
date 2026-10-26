<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $fillable = [
        'title',
        'description',
        'notice_date',
        'publish_date',
        'attachment',
        'featured',
        'status',
    ];

    protected $casts = [
        'notice_date'  => 'date:Y-m-d',
        'publish_date' => 'date:Y-m-d',
        'featured'     => 'boolean',
    ];

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }
}