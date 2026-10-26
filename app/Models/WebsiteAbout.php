<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteAbout extends Model
{
    protected $fillable = [
        'about_title',
        'about_subtitle',
        'about_description',
        'about_image',
        'school_history',
        'mission',
        'vision',
        'core_values',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}