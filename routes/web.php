<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ClassesmanagementController;
use App\Http\Controllers\CollectFeesController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\FeesManagementController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicAboutController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\PublicResultController;
use App\Http\Controllers\ResultInputController; // ✅ রিনেম করা হয়েছে
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentManagementController;
use App\Http\Controllers\StudentResultController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebsiteAboutController;
use App\Http\Controllers\WebsiteContactController;
use App\Http\Controllers\WebsiteEventController;
use App\Http\Controllers\WebsiteHeroController;
use App\Http\Controllers\WebsiteSettingsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

// ============================================================
// ✅ HELPER — Admin/Staff Check (middleware ছাড়াই)
// ============================================================
if (!function_exists('checkAdmin')) {
    function checkAdmin()
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized — Admin access required.');
        }
        return null;
    }
}


if (!function_exists('checkStaff')) {
    function checkStaff()
    {
        $user = Auth::user();    // ← Facade — editor জানে

        if (!$user) {
            return redirect()->route('login');
        }

        if (!in_array($user->role, ['admin', 'teacher'], true)) {
            abort(403, 'Unauthorized — Staff access required.');
        }

        return null;
    }
}

// ============================================================
// PUBLIC ROUTES
// ============================================================
Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/teachers', [TeacherController::class, 'publicIndex'])->name('teachers');

Route::get('/result', [PublicResultController::class, 'index'])->name('result');
Route::post('/result/fetch', [PublicResultController::class, 'fetch'])->name('result.fetch');

Route::get('/contact', [PublicContactController::class, 'index'])->name('contact');
Route::get('/about', [PublicAboutController::class, 'index'])->name('about');

Route::get('/student/{student}/verify', [StudentController::class, 'verify'])->name('students.verify');


// ============================================================
// ✅ ADMIN-ONLY ROUTES — Inline Check
// ============================================================
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard — admin only
    Route::get('/dashboard', function () {
        if ($r = checkAdmin()) return $r;
        return app(DashboardController::class)->index(request());
    })->name('dashboard');

    // =========================
    // Users Management
    // =========================
    Route::get('/users', function () {
        if ($r = checkAdmin()) return $r;
        return app(UserController::class)->index(request());
    })->name('users');

    Route::post('/users', function () {
        if ($r = checkAdmin()) return $r;
        return app(UserController::class)->store(request());
    })->name('users.store');

    Route::put('/users/{id}', function ($id) {
        if ($r = checkAdmin()) return $r;
        return app(UserController::class)->update(request(), $id);
    })->name('users.update');

    Route::delete('/users/{id}', function ($id) {
        if ($r = checkAdmin()) return $r;
        return app(UserController::class)->destroy(request(), $id);
    })->name('users.destroy');

    // =========================
    // Website Management — admin only
    // =========================
    Route::prefix('website-management')->name('website-management.')->group(function () {

        Route::get('/settings', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteSettingsController::class)->edit(request());
        })->name('settings');

        Route::post('/settings', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteSettingsController::class)->update(request());
        })->name('settings.update');

        Route::get('/home-page', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteHeroController::class)->edit(request());
        })->name('home-page');

        Route::post('/home-page', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteHeroController::class)->update(request());
        })->name('home-page.update');

        Route::get('/about-school', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteAboutController::class)->edit(request());
        })->name('about-school');

        Route::post('/about-school', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteAboutController::class)->update(request());
        })->name('about-school.update');

        Route::get('/events', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteEventController::class)->index(request());
        })->name('events');

        Route::post('/events', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteEventController::class)->store(request());
        })->name('events.store');

        Route::post('/events/{event}', function ($event) {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteEventController::class)->update(request(), $event);
        })->name('events.update');

        Route::delete('/events/{event}', function ($event) {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteEventController::class)->destroy(request(), $event);
        })->name('events.destroy');

        Route::get('/contact-info', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteContactController::class)->edit(request());
        })->name('contact-info');

        Route::post('/contact-info', function () {
            if ($r = checkAdmin()) return $r;
            return app(WebsiteContactController::class)->update(request());
        })->name('contact-info.update');

        Route::get('/messages', function () {
            if ($r = checkAdmin()) return $r;
            return app(ContactMessageController::class)->index(request());
        })->name('messages');

        Route::post('/messages/{message}/read', function ($message) {
            if ($r = checkAdmin()) return $r;
            return app(ContactMessageController::class)->markRead(request(), $message);
        })->name('messages.mark-read');

        Route::post('/messages/{message}/unread', function ($message) {
            if ($r = checkAdmin()) return $r;
            return app(ContactMessageController::class)->markUnread(request(), $message);
        })->name('messages.mark-unread');

        Route::post('/messages/{message}/reply', function ($message) {
            if ($r = checkAdmin()) return $r;
            return app(ContactMessageController::class)->reply(request(), $message);
        })->name('messages.reply');

        Route::delete('/messages/{message}', function ($message) {
            if ($r = checkAdmin()) return $r;
            return app(ContactMessageController::class)->destroy(request(), $message);
        })->name('messages.destroy');

        Route::get('/notices', function () {
            if ($r = checkAdmin()) return $r;
            return app(NoticeController::class)->index(request());
        })->name('notices');

        Route::post('/notices', function () {
            if ($r = checkAdmin()) return $r;
            return app(NoticeController::class)->store(request());
        })->name('notices.store');

        Route::post('/notices/{notice}', function ($notice) {
            if ($r = checkAdmin()) return $r;
            return app(NoticeController::class)->update(request(), $notice);
        })->name('notices.update');

        Route::delete('/notices/{notice}', function ($notice) {
            if ($r = checkAdmin()) return $r;
            return app(NoticeController::class)->destroy(request(), $notice);
        })->name('notices.destroy');

        Route::post('/notices/{notice}/toggle-featured', function ($notice) {
            if ($r = checkAdmin()) return $r;
            return app(NoticeController::class)->toggleFeatured(request(), $notice);
        })->name('notices.toggle-featured');

        Route::get('/teachers-staff', function () {
            if ($r = checkAdmin()) return $r;
            return app(TeacherController::class)->index(request());
        })->name('teachers-staff');

        Route::post('/teachers-staff', function () {
            if ($r = checkAdmin()) return $r;
            return app(TeacherController::class)->store(request());
        })->name('teachers-staff.store');

        Route::post('/teachers-staff/{teacher}', function ($teacher) {
            if ($r = checkAdmin()) return $r;
            return app(TeacherController::class)->update(request(), $teacher);
        })->name('teachers-staff.update');

        Route::delete('/teachers-staff/{teacher}', function ($teacher) {
            if ($r = checkAdmin()) return $r;
            return app(TeacherController::class)->destroy(request(), $teacher);
        })->name('teachers-staff.destroy');

        Route::get('/gallery', function () {
            if ($r = checkAdmin()) return $r;
            return Inertia::render('WebsiteManagement/Gallery');
        })->name('gallery');
    });
});


// ============================================================
// ✅ STAFF ROUTES (Admin + Teacher) — Inline Check
// ============================================================
Route::middleware(['auth', 'verified'])->group(function () {

    // Classes
    Route::get('/classes', function () {
        if ($r = checkStaff()) return $r;
        return app(SchoolClassController::class)->index(request());
    })->name('classes.index');

    Route::get('/classes/create', function () {
        if ($r = checkStaff()) return $r;
        return app(SchoolClassController::class)->create(request());
    })->name('classes.create');

    Route::post('/classes', function () {
        if ($r = checkStaff()) return $r;
        return app(SchoolClassController::class)->store(request());
    })->name('classes.store');

    Route::get('/classes/{class}', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(SchoolClassController::class)->show(request(), $class);
    })->name('classes.show');

    Route::get('/classes/{class}/edit', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(SchoolClassController::class)->edit(request(), $class);
    })->name('classes.edit');

    Route::put('/classes/{class}', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(SchoolClassController::class)->update(request(), $class);
    })->name('classes.update');

    Route::delete('/classes/{class}', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(SchoolClassController::class)->destroy(request(), $class);
    })->name('classes.destroy');

    // Classes Management
    Route::get('/classesmanagement', function () {
        if ($r = checkStaff()) return $r;
        return app(ClassesmanagementController::class)->index(request());
    })->name('classesmanagement.index');

    Route::get('/classesmanagement/create', function () {
        if ($r = checkStaff()) return $r;
        return app(ClassesmanagementController::class)->create(request());
    })->name('classesmanagement.create');

    Route::post('/classesmanagement', function () {
        if ($r = checkStaff()) return $r;
        return app(ClassesmanagementController::class)->store(request());
    })->name('classesmanagement.store');

    Route::get('/classesmanagement/{class}', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(ClassesmanagementController::class)->show(request(), $class);
    })->name('classesmanagement.show');

    Route::get('/classesmanagement/{class}/edit', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(ClassesmanagementController::class)->edit(request(), $class);
    })->name('classesmanagement.edit');

    Route::put('/classesmanagement/{class}', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(ClassesmanagementController::class)->update(request(), $class);
    })->name('classesmanagement.update');

    Route::delete('/classesmanagement/{class}', function ($class) {
        if ($r = checkStaff()) return $r;
        return app(ClassesmanagementController::class)->destroy(request(), $class);
    })->name('classesmanagement.destroy');

    // Events (authenticated staff view)
    Route::get('/events', function () {
        if ($r = checkStaff()) return $r;
        return app(WebsiteEventController::class)->index(request());
    })->name('website.events.index');

    // Attendance
    Route::get('/attendance', function () {
        if ($r = checkStaff()) return $r;
        return app(AttendanceController::class)->index(request());
    })->name('attendance.index');

    Route::get('/attendance/class/{classId}', function ($classId) {
        if ($r = checkStaff()) return $r;
        return app(AttendanceController::class)->show(request(), $classId);
    })->name('attendance.show');

    Route::post('/attendance/store', function () {
        if ($r = checkStaff()) return $r;
        return app(AttendanceController::class)->store(request());
    })->name('attendance.store');

    // Exams
    Route::get('/exams', function () {
        if ($r = checkStaff()) return $r;
        return Inertia::render('exams');
    })->name('exams');

    // ✅ Results — ResultInputController
    Route::get('/results', function () {
        if ($r = checkStaff()) return $r;
        return app(ResultInputController::class)->index(request());
    })->name('results.index');

    Route::get('/results/class/{classId}', function ($classId) {
        if ($r = checkStaff()) return $r;
        return app(ResultInputController::class)->show(request(), $classId);
    })->name('results.show');

    Route::post('/results/class/{classId}', function ($classId) {
        if ($r = checkStaff()) return $r;
        return app(ResultInputController::class)->store(request(), $classId);
    })->name('results.store');

    Route::post('/results/class/{classId}/lock', function ($classId) {
        if ($r = checkStaff()) return $r;
        return app(ResultInputController::class)->lock(request(), $classId);
    })->name('results.lock');

    Route::post('/results/class/{classId}/unlock', function ($classId) {
        if ($r = checkStaff()) return $r;
        return app(ResultInputController::class)->unlock(request(), $classId);
    })->name('results.unlock');

    // Student Results
    Route::get('/student-results', function () {
        if ($r = checkStaff()) return $r;
        return app(StudentResultController::class)->index(request());
    })->name('student.results');

    Route::get('/student-results/class/{classId}', function ($classId) {
        if ($r = checkStaff()) return $r;
        return app(StudentResultController::class)->show(request(), $classId);
    })->name('student.results.show');


        // ⭐ Student Results — Shift to Next Class
    Route::post('/student-results/shift', function (\Illuminate\Http\Request $request) {
        if ($r = checkStaff()) return $r;
        return app(StudentResultController::class)->shift($request);
    })->name('student.results.shift');

    Route::get('/student-results/shift-status', function () {
        if ($r = checkStaff()) return $r;
        return response()->json(
            \Illuminate\Support\Facades\Cache::get('shift_result_' . auth()->id(), ['done' => true])
        );
    })->name('student.results.shift-status');

    Route::delete('/student-results/reset-shift/{recordId}', function ($recordId) {
        if ($r = checkStaff()) return $r;
        return app(StudentResultController::class)->resetShift(request(), $recordId);
    })->name('student.results.reset-shift');

    // Students
    Route::get('/students', function () {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->index(request());
    })->name('students.index');

    Route::get('/students/create', function () {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->create(request());
    })->name('students.create');

    Route::get('/students/generate-id', function () {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->generateId(request());
    })->name('students.generate-id');

    Route::post('/students', function () {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->store(request());
    })->name('students.store');

    Route::get('/students/{student}', function ($student) {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->show(request(), $student);
    })->name('students.show');

    Route::get('/students/{student}/edit', function ($student) {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->edit(request(), $student);
    })->name('students.edit');

    Route::put('/students/{student}', function ($student) {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->update(request(), $student);
    })->name('students.update');

    Route::delete('/students/{student}', function ($student) {
        if ($r = checkStaff()) return $r;
        return app(StudentController::class)->destroy(request(), $student);
    })->name('students.destroy');

    // Student Management
    Route::get('/studentmanagement', function () {
        if ($r = checkStaff()) return $r;
        return app(StudentManagementController::class)->index(request());
    })->name('studentmanagement');

    Route::put('/studentmanagement/{student}', function (\App\Models\Student $student) {
        if ($r = checkStaff()) return $r;
        return app(StudentManagementController::class)->update(request(), $student);
    })->name('studentmanagement.update');

    Route::post('/studentmanagement/{student}', function (\App\Models\Student $student) {
        if ($r = checkStaff()) return $r;
        return app(StudentManagementController::class)->update(request(), $student);
    })->name('studentmanagement.update.post');

    Route::delete('/studentmanagement/{student}', function ($student) {
        if ($r = checkStaff()) return $r;
        return app(StudentManagementController::class)->destroy(request(), $student);
    })->name('studentmanagement.destroy');

    // Fees
    Route::get('/fees', function () {
        if ($r = checkStaff()) return $r;
        return app(CollectFeesController::class)->index(request());
    })->name('fees.index');

    Route::get('/fees/class/{classId}', function ($classId) {
        if ($r = checkStaff()) return $r;
        return app(CollectFeesController::class)->show(request(), $classId);
    })->name('fees.show');

    Route::post('/fees/policy/save', function () {
        if ($r = checkStaff()) return $r;
        return app(CollectFeesController::class)->savePolicy(request());
    })->name('fees.policy.save');

    Route::post('/fees/collect-payment', function () {
        if ($r = checkStaff()) return $r;
        return app(CollectFeesController::class)->collectPayment(request());
    })->name('fees.collect.payment');

    Route::get('/fees/create', function () {
        if ($r = checkStaff()) return $r;
        return app(FeeController::class)->create(request());
    })->name('fees.create');

    Route::post('/fees/create', function () {
        if ($r = checkStaff()) return $r;
        return app(FeeController::class)->store(request());
    })->name('fees.create.store');
});


// ============================================================
// PROFILE ROUTES
// ============================================================
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

//Gallery Routes — ✅ Admin only
Route::middleware(['auth'])->group(function () {
    // ... apnar existing routes ...

    Route::get('/gallery', function () {
        if ($r = checkAdmin()) return $r;
        return app(GalleryController::class)->index(request());
    })->name('galleries.index');

    Route::post('/gallery', function () {
        if ($r = checkAdmin()) return $r;
        return app(GalleryController::class)->store(request());
    })->name('galleries.store');

    Route::put('/gallery/{gallery}', function ($gallery) {
        if ($r = checkAdmin()) return $r;
        return app(GalleryController::class)->update(request(), $gallery);
    })->name('galleries.update');

    Route::delete('/gallery/{gallery}', function ($gallery) {
        if ($r = checkAdmin()) return $r;
        return app(GalleryController::class)->destroy(request(), $gallery);
    })->name('galleries.destroy');
});

require __DIR__.'/auth.php';