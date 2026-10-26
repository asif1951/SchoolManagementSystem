<?php

namespace App\Http\Middleware;

use App\Models\WebsiteSetting;
use App\Models\WebsiteHero;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * ✅ Global shared props — sob page e accessible.
     */
    public function share(Request $request): array
    {
        // School settings — cache 5 minutes for performance
        $school = cache()->remember('website_settings', 300, function () {
            return WebsiteSetting::first();
        });

        // Hero — cache 5 minutes for performance
        $hero = cache()->remember('website_hero', 300, function () {
            return WebsiteHero::first();
        });

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
            // ✅ Global school settings
            'school' => [
                'name'         => $school?->school_name ?? config('app.name', 'School'),
                'motto'        => $school?->school_motto,
                'logo'         => $school?->school_logo,
                'favicon'      => $school?->favicon,
                'phone'        => $school?->phone,
                'email'        => $school?->email,
                'address'      => $school?->address,
                'facebook_url' => $school?->facebook_url,
                'youtube_url'  => $school?->youtube_url,
                'instagram_url'=> $school?->instagram_url,
            ],
            // ✅ Global hero (image + title + subtitle)
            'hero' => [
                'title'     => $hero?->hero_title,
                'subtitle'  => $hero?->hero_subtitle,
                'image'     => $hero?->hero_image,
                'image_alt' => $hero?->hero_image_alt,
            ],
        ]);
    }
}