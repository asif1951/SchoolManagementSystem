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
                'user'      => $request->user(),
                // What this user's role allows (e.g. "fees.collect", "attendance.take:own", or "*").
                // Used only to show/hide menus and buttons — the server enforces every rule again.
                'abilities' => $request->user()?->grantedAbilities() ?? [],
            ],
            // the student module's labels (English or Bangla, per the user's choice); sent only on its own pages
            'locale' => app()->getLocale(),
            'i18n'   => fn () => match (true) {
                // the student profile also carries an attendance box
                $request->is('student-module/students/*') => ['student' => trans('student'), 'attendance' => trans('attendance')],
                $request->is('student-module*') => ['student' => trans('student')],
                $request->is('apply') => ['student' => trans('student')],
                $request->is('attendance*') => ['attendance' => trans('attendance'), 'student' => \Illuminate\Support\Arr::only(trans('student'), ['queued', 'working', 'finished', 'failed'])],
                // the progress bar of background tasks is shared with the student module: it needs its few words too
                $request->is('teaching*') => ['teaching' => trans('teaching'), 'student' => \Illuminate\Support\Arr::only(trans('student'), ['queued', 'working', 'finished', 'failed'])],
                default => null,
            },
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