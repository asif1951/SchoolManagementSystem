<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteContact extends Model
{
    protected $fillable = [
        'address',
        'phone',
        'alternative_phone',
        'email',
        'alternative_email',
        'google_maps_url',
        'office_hours',
        'facebook_url',
        'youtube_url',
        'instagram_url',
    ];

    public static function current(): self
    {
        // "the first row", not "id 1": `id` is not mass-assignable, so an id lookup would make a new row on every call once the counter passes 1
        return static::query()->orderBy('id')->first() ?? static::create([])->fresh();
    }
}