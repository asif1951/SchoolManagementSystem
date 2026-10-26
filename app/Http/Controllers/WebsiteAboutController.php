<?php

namespace App\Http\Controllers;

use App\Models\WebsiteAbout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class WebsiteAboutController extends Controller
{
    public function edit()
    {
        $about = WebsiteAbout::current();

        Log::info('About edit page loaded', ['id' => $about->id]);

        return Inertia::render('WebsiteManagement/AboutSchool', [
            'about' => [
                'about_title'       => $about->about_title,
                'about_subtitle'    => $about->about_subtitle,
                'about_description' => $about->about_description,
                'about_image'       => $about->about_image,
                'school_history'    => $about->school_history,
                'mission'           => $about->mission,
                'vision'            => $about->vision,
                'core_values'       => $about->core_values,
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
            'about_title'       => 'required|string|max:255',
            'about_subtitle'    => 'nullable|string|max:255',
            'about_description' => 'nullable|string|max:5000',
            'about_image'       => 'nullable|file|image|max:20480',
            'school_history'    => 'nullable|string|max:5000',
            'mission'           => 'nullable|string|max:2000',
            'vision'            => 'nullable|string|max:2000',
            'core_values'       => 'nullable|string|max:2000',
        ]);

        $about = WebsiteAbout::current();

        if ($request->hasFile('about_image')) {
            if ($about->about_image && Storage::disk('public')->exists($about->about_image)) {
                Storage::disk('public')->delete($about->about_image);
            }
            $validated['about_image'] = $request->file('about_image')
                ->store('website/about', 'public');
        } else {
            unset($validated['about_image']);
        }

        $about->update($validated);

        return redirect()
            ->route('website-management.about-school')
            ->with('success', 'About page saved successfully! ✅');
    }
}