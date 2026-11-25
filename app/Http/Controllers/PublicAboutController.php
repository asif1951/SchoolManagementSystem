<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PublicAboutController extends Controller
{
    public function index()
    {
        // ---------- About section (from website_abouts table) ----------
        $about = Cache::remember('website_about', 60, function () {
            return DB::table('website_abouts')->first();
        });

        // ---------- Website settings (logo, motto, established_year etc.) ----------
        $settings = null;
        $settings = DB::table('website_settings')->first();

        // ---------- Teachers preview (top 4 active) ----------
        $teachers = Cache::remember('about_teachers_preview_v2', 60, function () {
            // Base columns
            $columns = ['id', 'name', 'designation', 'department', 'qualification', 'photo', 'phone', 'email'];

            return DB::table('teachers')
                ->where('status', 'active')
                ->orderBy('order', 'asc')
                ->orderBy('name', 'asc')
                ->limit(4)
                ->get($columns);
        });

        // ---------- Stats (same as Welcome) ----------
        $currentYear = (int) date('Y');

        $totalStudents = 0;
        $q = DB::table('students')->whereNull('deleted_at');
        $q->where('academic_year', (string) $currentYear);
        $q->where('status', 'active');
        $totalStudents = $q->count();

        $totalTeachers = 0;
        $q = DB::table('teachers');
        $q->where('status', 'active');
        $totalTeachers = $q->count();

        $totalClasses = 0;
        $q = DB::table('classes');
        $q->where('status', 'active');
        $q->where('academic_year', (string) $currentYear);
        $totalClasses = $q->distinct()->count('class_name');

        $establishedYear = 2009;
        if ($settings && !empty($settings->established_year)) {
            $establishedYear = (int) $settings->established_year;
        }
        $yearsOfExcellence = max(0, $currentYear - $establishedYear);

        return Inertia::render('PublicAbout', [
            'canLogin'    => \Route::has('login'),
            'canRegister' => \Route::has('register'),

            'about' => $about ? [
                'title'       => $about->about_title       ?? null,
                'subtitle'    => $about->about_subtitle    ?? null,
                'description' => $about->about_description ?? null,
                'image'       => $about->about_image       ?? null,
                'history'     => $about->school_history    ?? null,
                'mission'     => $about->mission           ?? null,
                'vision'      => $about->vision            ?? null,
                'core_values' => $about->core_values       ?? null,
            ] : null,

            'teachers' => $teachers->map(fn ($t) => [
                'id'            => $t->id,
                'name'          => $t->name,
                'designation'   => $t->designation,
                'department'    => $t->department,
                'qualification' => $t->qualification,
                'photo'         => $t->photo,
                'phone'         => $t->phone ?? null,
                'email'         => $t->email ?? null,
            ])->values(),

            'stats' => [
                'students'          => $totalStudents,
                'teachers'          => $totalTeachers,
                'classes'           => $totalClasses,
                'yearsOfExcellence' => $yearsOfExcellence,
            ],
        ]);
    }
}