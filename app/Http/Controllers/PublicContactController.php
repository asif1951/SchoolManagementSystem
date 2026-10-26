<?php

namespace App\Http\Controllers;

use App\Models\WebsiteContact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Route;

class PublicContactController extends Controller
{
    /**
     * Show the public contact page with data from `website_contacts`.
     */
    public function index()
    {
        $contact = Cache::remember('website_contact', 60, function () {
            if (!Schema::hasTable('website_contacts')) return null;

            return WebsiteContact::first();
        });

        return Inertia::render('PublicContact', [
            'canLogin'    => Route::has('login'),
            'canRegister' => \Route::has('register'),

            // Contact details from the `website_contacts` table
            'contact' => $contact ? [
                'address'           => $contact->address,
                'phone'             => $contact->phone,
                'alternative_phone' => $contact->alternative_phone,
                'email'             => $contact->email,
                'alternative_email' => $contact->alternative_email,
                'google_maps_url'   => $contact->google_maps_url,
                'office_hours'      => $contact->office_hours,
                'facebook_url'      => $contact->facebook_url,
                'youtube_url'       => $contact->youtube_url,
                'instagram_url'     => $contact->instagram_url,
            ] : null,
        ]);
    }
}