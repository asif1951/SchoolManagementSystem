<?php

namespace App\Http\Controllers;

use App\Models\WebsiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class WebsiteSettingsController extends Controller
{
    /**
     * Show the settings page with existing data.
     */
    public function edit()
    {
        $settings = WebsiteSetting::current();

        return Inertia::render('WebsiteManagement/Settings', [
            'settings' => $settings,
        ]);
    }

    /**
     * Save / Update the settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            // School Info
            'school_name'        => 'required|string|max:255',
            'school_motto'       => 'nullable|string|max:255',
            'established_year'   => 'nullable|integer|min:1800|max:2100',
            'short_description'  => 'nullable|string|max:1000',
            'school_logo'        => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'favicon'            => 'nullable|file|mimes:png,svg,ico|max:1024',

            // Contact
            'phone'              => 'nullable|string|max:30',
            'alternative_phone'  => 'nullable|string|max:30',
            'email'              => 'nullable|email|max:255',
            'address'            => 'nullable|string|max:500',
            'office_hours'       => 'nullable|string|max:255',
            'google_maps_url'    => 'nullable|string|max:2000',

            // Social
            'facebook_url'       => 'nullable|url|max:500',
            'youtube_url'        => 'nullable|url|max:500',
            'instagram_url'      => 'nullable|url|max:500',
            'linkedin_url'       => 'nullable|url|max:500',

            // Footer
            'footer_description' => 'nullable|string|max:1000',
            'footer_copyright'   => 'nullable|string|max:500',
        ]);

        $settings = WebsiteSetting::current();

        // ✅ Handle School Logo Upload
        if ($request->hasFile('school_logo')) {
            if ($settings->school_logo && Storage::disk('public')->exists($settings->school_logo)) {
                Storage::disk('public')->delete($settings->school_logo);
            }
            $validated['school_logo'] = $request->file('school_logo')
                ->store('website/logo', 'public');
        } else {
            unset($validated['school_logo']);
        }

        // ✅ Handle Favicon Upload
        if ($request->hasFile('favicon')) {
            if ($settings->favicon && Storage::disk('public')->exists($settings->favicon)) {
                Storage::disk('public')->delete($settings->favicon);
            }
            $validated['favicon'] = $request->file('favicon')
                ->store('website/favicon', 'public');
        } else {
            unset($validated['favicon']);
        }

        $settings->update($validated);

        return redirect()
            ->route('website-management.settings')
            ->with('success', 'Website settings saved successfully! ✅');
    }
}