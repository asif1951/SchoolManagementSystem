<?php

namespace App\Http\Controllers;

use App\Models\WebsiteHero;
use App\Models\WebsiteAbout;
use App\Models\Notice;
use App\Models\WebsiteEvent;
use App\Models\Teacher;
use App\Models\SchoolClass;
use App\Models\Gallery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        // ✅ Hero
        $hero = Cache::remember('home_hero', 60, fn () => WebsiteHero::first());

        // ✅ About
        $about = Cache::remember('home_about', 60, fn () => WebsiteAbout::first());

        // ============================================================
        // ✅ NOTICES — backend pagination
        // ============================================================
        $noticesPerPage = 5;
        $noticesPage    = (int) request()->get('notices_page', 1);
        if ($noticesPage < 1) $noticesPage = 1;

        if (class_exists(Notice::class)) {
            $noticesPaginator = Notice::where('status', 'published')
                ->orderBy('featured', 'desc')
                ->orderBy('notice_date', 'desc')
                ->paginate(
                    $noticesPerPage,
                    ['id', 'title', 'description', 'notice_date', 'featured'],
                    'notices_page',
                    $noticesPage
                )
                ->withQueryString();
        } else {
            $noticesPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
                [],
                0,
                $noticesPerPage,
                $noticesPage,
                ['path' => request()->url(), 'pageName' => 'notices_page']
            );
        }

        $notices = [
            'data' => collect($noticesPaginator->items())->map(fn ($n) => [
                'id'          => $n->id,
                'title'       => $n->title,
                'description' => $n->description,
                'notice_date' => $n->notice_date ? \Carbon\Carbon::parse($n->notice_date)->format('Y-m-d') : null,
                'featured'    => (bool) $n->featured,
            ])->values(),
            'meta' => [
                'current_page'  => $noticesPaginator->currentPage(),
                'last_page'     => $noticesPaginator->lastPage(),
                'per_page'      => $noticesPaginator->perPage(),
                'total'         => $noticesPaginator->total(),
                'from'          => $noticesPaginator->firstItem(),
                'to'            => $noticesPaginator->lastItem(),
                'prev_page_url' => $noticesPaginator->previousPageUrl(),
                'next_page_url' => $noticesPaginator->nextPageUrl(),
            ],
        ];

        // ============================================================
        // ✅ EVENTS — backend pagination
        // ============================================================
        $eventsPerPage = 5;
        $eventsPage    = (int) request()->get('events_page', 1);
        if ($eventsPage < 1) $eventsPage = 1;

        if (class_exists(WebsiteEvent::class)) {
            $eventsPaginator = WebsiteEvent::whereIn('status', ['upcoming', 'ongoing'])
                ->where('event_date', '>=', now()->subDays(30)->toDateString())
                ->orderBy('event_date', 'asc')
                ->paginate(
                    $eventsPerPage,
                    ['id', 'title', 'event_date', 'event_time', 'location', 'event_image', 'status'],
                    'events_page',
                    $eventsPage
                )
                ->withQueryString();
        } else {
            $eventsPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
                [],
                0,
                $eventsPerPage,
                $eventsPage,
                ['path' => request()->url(), 'pageName' => 'events_page']
            );
        }

        $events = [
            'data' => collect($eventsPaginator->items())->map(fn ($e) => [
                'id'          => $e->id,
                'title'       => $e->title,
                'event_date'  => $e->event_date ? \Carbon\Carbon::parse($e->event_date)->format('Y-m-d') : null,
                'event_time'  => $e->event_time,
                'location'    => $e->location,
                'event_image' => $e->event_image,
                'status'      => $e->status,
            ])->values(),
            'meta' => [
                'current_page'  => $eventsPaginator->currentPage(),
                'last_page'     => $eventsPaginator->lastPage(),
                'per_page'      => $eventsPaginator->perPage(),
                'total'         => $eventsPaginator->total(),
                'from'          => $eventsPaginator->firstItem(),
                'to'            => $eventsPaginator->lastItem(),
                'prev_page_url' => $eventsPaginator->previousPageUrl(),
                'next_page_url' => $eventsPaginator->nextPageUrl(),
            ],
        ];

        // ✅ Teachers — top 4 active teachers
        $teachers = Cache::remember('home_teachers', 60, function () {
            return class_exists(Teacher::class)
                ? Teacher::where('status', 'active')
                    ->orderBy('order', 'asc')
                    ->orderBy('name', 'asc')
                    ->limit(4)
                    ->get([
                        'id', 'name', 'designation', 'department',
                        'qualification', 'photo', 'bio', 'phone', 'email',
                    ])
                : collect();
        });

        // ============================================================
        // ✅ GALLERY — backend pagination
        // ============================================================
        $galleryPerPage = 11;
        $galleryPage    = (int) request()->get('gallery_page', 1);
        if ($galleryPage < 1) $galleryPage = 1;

        if (class_exists(Gallery::class)) {
            $galleriesPaginator = Gallery::latest()
                ->paginate($galleryPerPage, ['id', 'title', 'image'], 'gallery_page', $galleryPage)
                ->withQueryString();
        } else {
            $galleriesPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
                [],
                0,
                $galleryPerPage,
                $galleryPage,
                ['path' => request()->url(), 'pageName' => 'gallery_page']
            );
        }

        $galleries = [
            'data' => collect($galleriesPaginator->items())->map(fn ($g) => [
                'id'    => $g->id,
                'title' => $g->title,
                'image' => $g->image,
            ])->values(),
            'meta' => [
                'current_page'  => $galleriesPaginator->currentPage(),
                'last_page'     => $galleriesPaginator->lastPage(),
                'per_page'      => $galleriesPaginator->perPage(),
                'total'         => $galleriesPaginator->total(),
                'from'          => $galleriesPaginator->firstItem(),
                'to'            => $galleriesPaginator->lastItem(),
                'prev_page_url' => $galleriesPaginator->previousPageUrl(),
                'next_page_url' => $galleriesPaginator->nextPageUrl(),
                'links'         => collect($galleriesPaginator->linkCollection())->map(fn ($l) => [
                    'url'    => $l['url'],
                    'label'  => $l['label'],
                    'active' => (bool) $l['active'],
                ])->values(),
            ],
        ];

        // ============================================================
        // ✅ DYNAMIC STATS
        // ============================================================
        $currentYear = (int) date('Y');

        $totalStudents = Cache::remember('home_total_students_' . $currentYear, 60, function () use ($currentYear) {
            if (!Schema::hasTable('students')) return 0;
            $query = DB::table('students');
            if (Schema::hasColumn('students', 'academic_year')) {
                $query->where('academic_year', (string) $currentYear);
            }
            if (Schema::hasColumn('students', 'status')) {
                $query->where('status', 'active');
            }
            return $query->count();
        });

        $totalTeachers = Cache::remember('home_total_teachers', 60, function () {
            if (!Schema::hasTable('teachers')) return 0;
            $query = DB::table('teachers');
            if (Schema::hasColumn('teachers', 'status')) {
                $query->where('status', 'active');
            }
            return $query->count();
        });

        $totalClasses = Cache::remember('home_total_classes_' . $currentYear, 60, function () use ($currentYear) {
            if (!Schema::hasTable('classes')) return 0;
            $query = DB::table('classes');
            if (Schema::hasColumn('classes', 'status')) {
                $query->where('status', 'active');
            }
            if (Schema::hasColumn('classes', 'academic_year')) {
                $query->where('academic_year', (string) $currentYear);
            }
            return $query->distinct()->count('class_name');
        });

        $yearsOfExcellence = Cache::remember('home_years_of_excellence_' . $currentYear, 60, function () use ($currentYear) {
            $establishedYear = 2009;
            if (Schema::hasTable('website_settings')) {
                $setting = DB::table('website_settings')->first();
                if ($setting && !empty($setting->established_year)) {
                    $establishedYear = (int) $setting->established_year;
                }
            }
            $diff = $currentYear - $establishedYear;
            return $diff > 0 ? $diff : 0;
        });

        // ============================================================
        // RENDER
        // ============================================================
        return Inertia::render('Welcome', [
            'canLogin'       => \Route::has('login'),
            'canRegister'    => \Route::has('register'),
            'laravelVersion' => \Illuminate\Foundation\Application::VERSION,
            'phpVersion'     => PHP_VERSION,

            'hero' => $hero ? [
                'title'     => $hero->hero_title,
                'subtitle'  => $hero->hero_subtitle,
                'image'     => $hero->hero_image,
                'image_alt' => $hero->hero_image_alt,
                'button1'   => [
                    'show' => (bool) $hero->button1_show,
                    'text' => $hero->button1_text,
                    'type' => $hero->button1_type,
                    'page' => $this->normalizePage($hero->button1_page),
                    'url'  => $hero->button1_url,
                ],
                'button2'   => [
                    'show' => (bool) $hero->button2_show,
                    'text' => $hero->button2_text,
                    'type' => $hero->button2_type,
                    'page' => $this->normalizePage($hero->button2_page),
                    'url'  => $hero->button2_url,
                ],
            ] : null,

            'about' => $about ? [
                'title'       => $about->about_title,
                'subtitle'    => $about->about_subtitle,
                'description' => $about->about_description,
                'image'       => $about->about_image,
                'history'     => $about->school_history,
                'mission'     => $about->mission,
                'vision'      => $about->vision,
                'core_values' => $about->core_values,
            ] : null,

            // ✅ Paginated notices { data, meta }
            'notices' => $notices,

            // ✅ Paginated events { data, meta }
            'events' => $events,

            'teachers' => $teachers->map(fn ($t) => [
                'id'            => $t->id,
                'name'          => $t->name,
                'designation'   => $t->designation,
                'department'    => $t->department,
                'qualification' => $t->qualification,
                'photo'         => $t->photo,
                'bio'           => $t->bio,
                'phone'         => $t->phone,
                'email'         => $t->email,
            ])->values(),

            'teachersCount' => $teachers->count(),

            // ✅ Paginated galleries { data, meta }
            'galleries' => $galleries,

            'stats' => [
                'students'          => $totalStudents,
                'teachers'          => $totalTeachers,
                'classes'           => $totalClasses,
                'yearsOfExcellence' => $yearsOfExcellence,
            ],
        ]);
    }

    private function normalizePage(?string $value): ?string
    {
        if (!$value) return $value;

        $aliases = [
            'student-results' => 'result',
            'student_result'  => 'result',
            'student-result'  => 'result',
            'public-result'   => 'result',
            'public_result'   => 'result',
            'result.index'    => 'result',
            'results'         => 'result',
        ];

        $key = strtolower(trim($value));

        return $aliases[$key] ?? $value;
    }
}