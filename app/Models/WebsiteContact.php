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
        return static::firstOrCreate(['id' => 1]);
    }
}