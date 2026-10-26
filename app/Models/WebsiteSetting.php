<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebsiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        // School Info
        'school_name',
        'school_motto',
        'established_year',
        'short_description',
        'school_logo',
        'favicon',

        // Contact
        'phone',
        'alternative_phone',
        'email',
        'address',
        'office_hours',
        'google_maps_url',

        // Social
        'facebook_url',
        'youtube_url',
        'instagram_url',
        'linkedin_url',

        // Footer
        'footer_description',
        'footer_copyright',
    ];

    /**
     * Always return a single settings row.
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}