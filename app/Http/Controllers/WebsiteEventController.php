<?php

namespace App\Http\Controllers;

use App\Models\WebsiteEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class WebsiteEventController extends Controller
{
    /**
     * List all events with filters.
     */
    public function index(Request $request)
    {
        $query = WebsiteEvent::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $events = $query->orderBy('event_date', 'desc')->get();

        return Inertia::render('WebsiteManagement/Events', [
            'events'  => $events,
            'filters' => [
                'search' => $request->search,
                'status' => $request->status ?? 'all',
            ],
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
        ]);
    }

    /**
     * Create a new event.
     */
    public function store(Request $request)
    {
        $validated = $this->validateEvent($request);

        if ($request->hasFile('event_image')) {
            $validated['event_image'] = $request->file('event_image')
                ->store('website/events', 'public');
        }

        WebsiteEvent::create($validated);

        return redirect()
            ->route('website-management.events')
            ->with('success', 'Event created successfully! ✅');
    }

    /**
     * Update an existing event.
     */
    public function update(Request $request, WebsiteEvent $event)
    {
        $validated = $this->validateEvent($request);

        if ($request->hasFile('event_image')) {
            // Delete old image
            if ($event->event_image && Storage::disk('public')->exists($event->event_image)) {
                Storage::disk('public')->delete($event->event_image);
            }
            $validated['event_image'] = $request->file('event_image')
                ->store('website/events', 'public');
        } else {
            unset($validated['event_image']);
        }

        $event->update($validated);

        return redirect()
            ->route('website-management.events')
            ->with('success', 'Event updated successfully! ✅');
    }

    /**
     * Delete an event.
     */
    public function destroy(WebsiteEvent $event)
    {
        if ($event->event_image && Storage::disk('public')->exists($event->event_image)) {
            Storage::disk('public')->delete($event->event_image);
        }

        $event->delete();

        return redirect()
            ->route('website-management.events')
            ->with('success', 'Event deleted successfully! ✅');
    }

    /**
     * Common validation rules.
     */
    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'title'       => 'required|string|max:255',
            'event_date'  => 'required|date',
            'event_time'  => 'nullable|date_format:H:i',
            'location'    => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'event_image' => 'nullable|file|image|max:20480',
            'status'      => 'required|in:upcoming,ongoing,completed,cancelled',
        ]);
    }
}