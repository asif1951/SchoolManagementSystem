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
        // "the first row", not "id 1": `id` is not mass-assignable, so an id lookup would make a new row on every call once the counter passes 1
        return static::query()->orderBy('id')->first() ?? static::create([])->fresh();
    }
}