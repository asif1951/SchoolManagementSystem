<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteEvent extends Model
{
    protected $fillable = [
        'title',
        'event_date',
        'event_time',
        'location',
        'description',
        'event_image',
        'status',
    ];

    protected $casts = [
        'event_date' => 'date:Y-m-d',
        'event_time' => 'string',
    ];

    /**
     * Upcoming events — used on homepage.
     */
    public static function upcoming(int $limit = 3)
    {
        return static::whereIn('status', ['upcoming', 'ongoing'])
            ->where('event_date', '>=', now()->toDateString())
            ->orderBy('event_date', 'asc')
            ->limit($limit)
            ->get();
    }
}