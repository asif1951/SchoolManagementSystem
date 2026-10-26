<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class NoticeController extends Controller
{
    public function index(Request $request)
    {
        $query = Notice::query();

        if ($request->filled('search')) {
            $query->where('title', 'like', "%{$request->search}%");
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $notices = $query
            ->orderBy('featured', 'desc')
            ->orderBy('notice_date', 'desc')
            ->get();

        return Inertia::render('WebsiteManagement/Notices', [
            'notices' => $notices,
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

    public function store(Request $request)
    {
        $validated = $this->validateNotice($request);

        if ($request->hasFile('attachment')) {
            $validated['attachment'] = $request->file('attachment')
                ->store('website/notices', 'public');
        }

        $validated['featured'] = $request->boolean('featured');

        Notice::create($validated);

        return redirect()
            ->route('website-management.notices')
            ->with('success', 'Notice published successfully! ✅');
    }

    public function update(Request $request, Notice $notice)
    {
        $validated = $this->validateNotice($request);

        if ($request->hasFile('attachment')) {
            if ($notice->attachment && Storage::disk('public')->exists($notice->attachment)) {
                Storage::disk('public')->delete($notice->attachment);
            }
            $validated['attachment'] = $request->file('attachment')
                ->store('website/notices', 'public');
        } else {
            unset($validated['attachment']);
        }

        $validated['featured'] = $request->boolean('featured');

        $notice->update($validated);

        return redirect()
            ->route('website-management.notices')
            ->with('success', 'Notice updated successfully! ✅');
    }

    public function destroy(Notice $notice)
    {
        if ($notice->attachment && Storage::disk('public')->exists($notice->attachment)) {
            Storage::disk('public')->delete($notice->attachment);
        }

        $notice->delete();

        return redirect()
            ->route('website-management.notices')
            ->with('success', 'Notice deleted successfully! ✅');
    }

    public function toggleFeatured(Notice $notice)
    {
        $notice->update(['featured' => ! $notice->featured]);

        return back()->with('success', $notice->featured ? 'Marked as featured!' : 'Removed from featured.');
    }

    private function validateNotice(Request $request): array
    {
        return $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string|max:5000',
            'notice_date'  => 'required|date',
            'publish_date' => 'nullable|date',
            'attachment'   => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:20480',
            'featured'     => 'boolean',
            'status'       => 'required|in:published,draft',
        ]);
    }
}