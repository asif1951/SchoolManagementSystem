<?php

namespace App\Http\Controllers;

use App\Models\WebsiteContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class WebsiteContactController extends Controller
{
    public function edit()
    {
        $contact = WebsiteContact::current();

        return Inertia::render('WebsiteManagement/ContactInfo', [
            'contact' => [
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
            ],
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'address'           => 'nullable|string|max:500',
            'phone'             => 'nullable|string|max:30',
            'alternative_phone' => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:255',
            'alternative_email' => 'nullable|email|max:255',
            'google_maps_url'   => 'nullable|string|max:2000',
            'office_hours'      => 'nullable|string|max:255',
            'facebook_url'      => 'nullable|string|max:500',
            'youtube_url'       => 'nullable|string|max:500',
            'instagram_url'     => 'nullable|string|max:500',
        ]);

        $contact = WebsiteContact::current();
        $contact->update($validated);

        Log::info('Contact info saved', ['id' => $contact->id]);

        return redirect()
            ->route('website-management.contact-info')
            ->with('success', 'Contact info saved successfully! ✅');
    }
}