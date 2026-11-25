<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebsiteHero extends Model
{
    use HasFactory;

    /** Saving or deleting the hero clears the cached copy the homepage reads. */
    protected static function booted(): void
    {
        $forget = fn () => cache()->forget('website_hero');

        static::saved($forget);
        static::deleted($forget);
    }

    protected $fillable = [
        'hero_title',
        'hero_subtitle',
        'hero_image',
        'hero_image_alt',
        'button1_show',
        'button1_text',
        'button1_type',
        'button1_page',
        'button1_url',
        'button2_show',
        'button2_text',
        'button2_type',
        'button2_page',
        'button2_url',
    ];

    protected $casts = [
        'button1_show' => 'boolean',
        'button2_show' => 'boolean',
    ];

    /**
     * Always return a single settings row.
     */
    public static function current(): self
    {
        // "the first row", not "id 1": `id` is not mass-assignable, so an id lookup would make a new row on every call once the counter passes 1
        return static::query()->orderBy('id')->first() ?? static::create([])->fresh();
    }
}