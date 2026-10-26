<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\WebsiteHero; // ✅ নতুন যোগ করুন
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class TeacherController extends Controller
{
    /**
     * Admin — list teachers.
     */
    public function index(Request $request)
    {
        $query = Teacher::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $teachers = $query
            ->orderBy('order', 'asc')
            ->orderBy('name')
            ->get();

        return Inertia::render('WebsiteManagement/TeachersStaff', [
            'teachers' => $teachers,
            'filters'  => [
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
     * Admin — create teacher.
     */
    public function store(Request $request)
    {
        $validated = $this->validateTeacher($request);

        if ($request->hasFile('photo')) {
            $validated['photo'] = $this->uploadAndCompressImage($request->file('photo'));
        }

        $validated['display_on_website'] = $request->boolean('display_on_website');
        $validated['order']              = (int) $request->input('order', 0);

        Teacher::create($validated);

        return redirect()
            ->route('website-management.teachers-staff')
            ->with('success', 'Teacher added successfully! ✅');
    }

    /**
     * Admin — update teacher.
     */
    public function update(Request $request, Teacher $teacher)
    {
        $validated = $this->validateTeacher($request, $teacher->id);

        if ($request->hasFile('photo')) {
            if ($teacher->photo && Storage::disk('public')->exists($teacher->photo)) {
                Storage::disk('public')->delete($teacher->photo);
            }
            $validated['photo'] = $this->uploadAndCompressImage($request->file('photo'));
        } else {
            unset($validated['photo']);
        }

        $validated['display_on_website'] = $request->boolean('display_on_website');
        $validated['order']              = (int) $request->input('order', $teacher->order ?? 0);

        $teacher->update($validated);

        return redirect()
            ->route('website-management.teachers-staff')
            ->with('success', 'Teacher updated successfully! ✅');
    }

    /**
     * Admin — delete teacher.
     */
    public function destroy(Teacher $teacher)
    {
        if ($teacher->photo && Storage::disk('public')->exists($teacher->photo)) {
            Storage::disk('public')->delete($teacher->photo);
        }

        $teacher->delete();

        return redirect()
            ->route('website-management.teachers-staff')
            ->with('success', 'Teacher deleted successfully! ✅');
    }

    /**
     * ✅ Public — Teachers page (with search + department filter + hero data).
     */
    public function publicIndex(Request $request)
    {
        $query = Teacher::where('status', 'active');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%");
            });
        }

        // Department filter
        if ($request->filled('department') && $request->department !== 'all') {
            $query->where('department', $request->department);
        }

        $teachers = $query
            ->orderBy('order', 'asc')
            ->orderBy('name', 'asc')
            ->get([
                'id', 'name', 'designation', 'department',
                'qualification', 'experience', 'photo',
                'bio', 'phone', 'email',
            ]);

        // Unique departments for filter dropdown
        $departments = Teacher::where('status', 'active')
            ->whereNotNull('department')
            ->distinct()
            ->pluck('department')
            ->filter()
            ->values();

        // ✅ Hero data from website_heroes table
        $hero = WebsiteHero::first();

        return Inertia::render('Teachers', [
            'teachers'    => $teachers,
            'departments' => $departments,
            'hero'        => $hero, // ✅ Hero ডেটা পাঠানো হলো
            'filters'     => [
                'search'     => $request->search ?? '',
                'department' => $request->department ?? 'all',
            ],
        ]);
    }

    /**
     * Validation rules.
     */
    private function validateTeacher(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'               => 'required|string|max:255',
            'photo'              => 'nullable|file|mimes:jpg,jpeg,png,webp|max:51200',
            'designation'        => 'nullable|string|max:255',
            'department'         => 'nullable|string|max:255',
            'qualification'      => 'nullable|string|max:255',
            'experience'         => 'nullable|string|max:255',
            'bio'                => 'nullable|string|max:2000',
            'phone'              => 'nullable|string|max:30',
            'email'              => 'nullable|email|max:255',
            'display_on_website' => 'boolean',
            'status'             => 'required|in:active,inactive',
            'order'              => 'nullable|integer|min:0|max:9999',
        ]);
    }

    /**
     * Upload + compress image.
     */
    private function uploadAndCompressImage($file): string
    {
        $manager = new ImageManager(new Driver());
        $ext     = strtolower($file->getClientOriginalExtension());

        $image = $manager->read($file->getRealPath());
        $image->scaleDown(width: 800, height: 800);

        Storage::disk('public')->makeDirectory('website/teachers');

        if ($ext === 'png') {
            $filename = 'website/teachers/' . uniqid('teacher_') . '_' . time() . '.png';
            $image->toPng()->save(Storage::disk('public')->path($filename));
        } else {
            $filename = 'website/teachers/' . uniqid('teacher_') . '_' . time() . '.jpg';
            $image->toJpeg(80)->save(Storage::disk('public')->path($filename));
        }

        Log::info('Teacher photo uploaded & compressed', [
            'original_size'   => $file->getSize(),
            'compressed_path' => $filename,
            'compressed_size' => Storage::disk('public')->size($filename),
        ]);

        return $filename;
    }
}