<?php

namespace App\Http\Controllers;

use App\Models\WebsiteHero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class WebsiteHeroController extends Controller
{
    /**
     * Show the hero management page with existing data from DB.
     */
    public function edit()
    {
        // ✅ DB theke data load — firstOrCreate ensures row exists
        $hero = WebsiteHero::current();

        // ✅ DEBUG — log check korun storage/logs/laravel.log e
        Log::info('Hero edit page loaded', [
            'id'         => $hero->id,
            'title'      => $hero->hero_title,
            'image'      => $hero->hero_image,
            'button1'    => $hero->button1_show,
            'button2'    => $hero->button2_show,
        ]);

        return Inertia::render('WebsiteManagement/HomePage', [
            'hero' => [
                'hero_title'      => $hero->hero_title,
                'hero_subtitle'   => $hero->hero_subtitle,
                'hero_image'      => $hero->hero_image,
                'hero_image_alt'  => $hero->hero_image_alt,
                'button1_show'    => (bool) $hero->button1_show,
                'button1_text'    => $hero->button1_text,
                'button1_type'    => $hero->button1_type ?? 'internal',
                'button1_page'    => $hero->button1_page,
                'button1_url'     => $hero->button1_url,
                'button2_show'    => (bool) $hero->button2_show,
                'button2_text'    => $hero->button2_text,
                'button2_type'    => $hero->button2_type ?? 'internal',
                'button2_page'    => $hero->button2_page,
                'button2_url'     => $hero->button2_url,
            ],
            'availablePages' => $this->availablePages(),
            'flash'          => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
        ]);
    }

    /**
     * Save / Update hero settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'hero_title'      => 'required|string|max:255',
            'hero_subtitle'   => 'nullable|string|max:1000',
            'hero_image'      => 'nullable|file|image|max:20480',
            'hero_image_alt'  => 'nullable|string|max:255',

            'button1_show'    => 'boolean',
            'button1_text'    => 'nullable|string|max:100',
            'button1_type'    => 'nullable|in:internal,external',
            'button1_page'    => 'nullable|string|max:100',
            'button1_url'     => 'nullable|string|max:500',

            'button2_show'    => 'boolean',
            'button2_text'    => 'nullable|string|max:100',
            'button2_type'    => 'nullable|in:internal,external',
            'button2_page'    => 'nullable|string|max:100',
            'button2_url'     => 'nullable|string|max:500',
        ]);

        $hero = WebsiteHero::current();

        // ✅ Handle image upload
        if ($request->hasFile('hero_image')) {
            // Delete old image if exists
            if ($hero->hero_image && Storage::disk('public')->exists($hero->hero_image)) {
                Storage::disk('public')->delete($hero->hero_image);
            }

            $validated['hero_image'] = $request->file('hero_image')
                ->store('website/hero', 'public');

            Log::info('Hero image uploaded', [
                'path' => $validated['hero_image'],
            ]);
        } else {
            unset($validated['hero_image']);
        }

        // ✅ Booleans — request theke properly cast
        $validated['button1_show'] = $request->boolean('button1_show');
        $validated['button2_show'] = $request->boolean('button2_show');

        // ✅ Clear irrelevant field based on type
        if (($validated['button1_type'] ?? 'internal') === 'internal') {
            $validated['button1_url'] = null;
        } else {
            $validated['button1_page'] = null;
        }

        if (($validated['button2_type'] ?? 'internal') === 'internal') {
            $validated['button2_url'] = null;
        } else {
            $validated['button2_page'] = null;
        }

        $hero->update($validated);

        Log::info('Hero settings saved', [
            'id'    => $hero->id,
            'title' => $hero->hero_title,
        ]);

        return redirect()
            ->route('website-management.home-page')
            ->with('success', 'Home page saved successfully! ✅');
    }

    /**
     * List of internal pages for CTA buttons.
     */
    private function availablePages(): array
    {
        return [
            ['key' => 'home',            'label' => 'Home'],
            ['key' => 'about-school',    'label' => 'About School'],
            ['key' => 'teachers-staff',  'label' => 'Teachers & Staff'],
            ['key' => 'notices',         'label' => 'Notices'],
            ['key' => 'events',          'label' => 'Events'],
            ['key' => 'gallery',         'label' => 'Gallery'],
            ['key' => 'contact',         'label' => 'Contact'],
            ['key' => 'student-results', 'label' => 'Check Result'],
            ['key' => 'admission',       'label' => 'Admission'],
        ];
    }
}