<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class PublicAboutController extends Controller
{
    public function index()
    {
        // ---------- About section (from website_abouts table) ----------
        $about = Cache::remember('website_about', 60, function () {
            if (!Schema::hasTable('website_abouts')) return null;
            return DB::table('website_abouts')->first();
        });

        // ---------- Website settings (logo, motto, established_year etc.) ----------
        $settings = null;
        if (Schema::hasTable('website_settings')) {
            $settings = DB::table('website_settings')->first();
        }

        // ---------- Teachers preview (top 4 active) ----------
        $teachers = Cache::remember('about_teachers_preview_v2', 60, function () {
            if (!Schema::hasTable('teachers')) return collect();

            // Base columns
            $columns = ['id', 'name', 'designation', 'department', 'qualification', 'photo'];

            // Contact columns (added only if they exist in the table)
            foreach (['phone', 'email'] as $col) {
                if (Schema::hasColumn('teachers', $col)) {
                    $columns[] = $col;
                }
            }

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
        if (Schema::hasTable('students')) {
            $q = DB::table('students');
            if (Schema::hasColumn('students', 'academic_year')) {
                $q->where('academic_year', (string) $currentYear);
            }
            if (Schema::hasColumn('students', 'status')) {
                $q->where('status', 'active');
            }
            $totalStudents = $q->count();
        }

        $totalTeachers = 0;
        if (Schema::hasTable('teachers')) {
            $q = DB::table('teachers');
            if (Schema::hasColumn('teachers', 'status')) {
                $q->where('status', 'active');
            }
            $totalTeachers = $q->count();
        }

        $totalClasses = 0;
        if (Schema::hasTable('classes')) {
            $q = DB::table('classes');
            if (Schema::hasColumn('classes', 'status')) {
                $q->where('status', 'active');
            }
            if (Schema::hasColumn('classes', 'academic_year')) {
                $q->where('academic_year', (string) $currentYear);
            }
            $totalClasses = $q->distinct()->count('class_name');
        }

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