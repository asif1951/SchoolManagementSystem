<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ClassesmanagementController;
use App\Http\Controllers\CollectFeesController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\ExamSettingsController;
use App\Http\Controllers\ExamSetupController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicAboutController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\PublicResultController;
use App\Http\Controllers\ResultInputController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudyGroupController;
use App\Http\Controllers\ClassSettingsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StudentStatusController;
use App\Http\Controllers\StudentModule\ChangeRequestController;
use App\Http\Controllers\StudentModule\ImportController;
use App\Http\Controllers\PublicApplyController;
use App\Http\Controllers\StudentModule\AdmissionPaymentController;
use App\Http\Controllers\StudentModule\CertificateController;
use App\Http\Controllers\StudentModule\OnlineAdmissionController;
use App\Http\Controllers\Routine\RoutineController;
use App\Http\Controllers\StudentModule\PhotoController;
use App\Http\Controllers\StudentModule\StudentReportController;
use App\Http\Controllers\StudentModule\StudentSubjectController;
use App\Http\Controllers\StudentModule\TransferController;
use App\Http\Controllers\StudentModule\PromotionController;
use App\Http\Controllers\StudentModule\StudentModuleController;
use App\Http\Controllers\StudentModule\TaskController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectTeacherController;
use App\Http\Controllers\StudentManagementController;
use App\Http\Controllers\StudentResultController;
use App\Http\Controllers\TabulationController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebsiteAboutController;
use App\Http\Controllers\WebsiteContactController;
use App\Http\Controllers\WebsiteEventController;
use App\Http\Controllers\WebsiteHeroController;
use App\Http\Controllers\WebsiteSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
| Every protected route lives inside one of the groups below, so a new route
| is protected by default simply by being placed in the right group:
|
|   auth     → must be logged in
|   verified → email verified
|   active   → account status must be "active" (suspended/inactive are logged out)
|   role:x   → role check (App\Http\Middleware\RoleMiddleware)
|
| Using controller actions (no closures) also makes `php artisan route:cache`
| possible in production.
*/

// ============================================================
// PUBLIC ROUTES
// ============================================================
Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/teachers', [TeacherController::class, 'publicIndex'])->name('teachers');

Route::get('/result', [PublicResultController::class, 'index'])->name('result');
Route::post('/result/fetch', [PublicResultController::class, 'fetch'])
    ->middleware('throttle:20,1')
    ->name('result.fetch');

Route::get('/contact', [PublicContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactMessageController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('contact.store');

Route::get('/about', [PublicAboutController::class, 'index'])->name('about');

// QR verification page — unguessable token, rate limited, minimal data.
Route::get('/student/{token}/verify', [StudentController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->where('token', '[0-9A-Za-z]{26}')
    ->name('students.verify');

// ============================================================
// ADMINISTRATION — each route asks for its own ability
// (admin has every ability; other roles get them from Roles & Permissions)
// ============================================================
// the public online admission form (no login): rate-limited, with a honeypot
Route::get('/apply', [PublicApplyController::class, 'show'])->name('apply.show');
Route::post('/apply', [PublicApplyController::class, 'store'])->middleware('throttle:5,10')->name('apply.store');

// language of the interface (English / Bangla), kept in the session; also used by the public form
Route::post('/locale', function (\Illuminate\Http\Request $request) {
    $locale = $request->validate(['locale' => ['required', 'in:en,bn']])['locale'];
    $request->session()->put('locale', $locale);

    return back();
})->middleware('throttle:30,1')->name('locale');

Route::middleware(['auth', 'verified', 'active'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('can:dashboard.view')->name('dashboard');

    // Users
    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Subject list (master data for classes and exams)
    Route::middleware('can:subjects.manage')->group(function () {
        Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
        Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
        Route::post('/subjects/bulk', [SubjectController::class, 'storeMany'])->name('subjects.store-many');
        Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

        Route::get('/study-groups', [StudyGroupController::class, 'index'])->name('study-groups.index');
        Route::post('/study-groups', [StudyGroupController::class, 'store'])->name('study-groups.store');
        Route::put('/study-groups/{group}', [StudyGroupController::class, 'update'])->name('study-groups.update');
        Route::delete('/study-groups/{group}', [StudyGroupController::class, 'destroy'])->name('study-groups.destroy');

        Route::get('/curriculum', [CurriculumController::class, 'index'])->name('curriculum.index');
        Route::post('/curriculum', [CurriculumController::class, 'store'])->name('curriculum.store');
        Route::post('/curriculum/bulk', [CurriculumController::class, 'storeMany'])->name('curriculum.store-many');
        Route::post('/curriculum/apply', [CurriculumController::class, 'apply'])->name('curriculum.apply');
        Route::put('/curriculum/{row}', [CurriculumController::class, 'update'])->name('curriculum.update');
        Route::delete('/curriculum/{row}', [CurriculumController::class, 'destroy'])->name('curriculum.destroy');
    });

    // Exam management — exams, result rules, grading scales, subject parts and marks
    Route::middleware('can:exams.manage')->group(function () {
        Route::get('/exams', [ExamController::class, 'index'])->name('exams.index');
        Route::post('/exams', [ExamController::class, 'store'])->name('exams.store');
        Route::post('/exam-policy', [ExamController::class, 'savePolicy'])->name('exams.policy');
        Route::put('/exams/{exam}', [ExamController::class, 'update'])->name('exams.update');
        Route::delete('/exams/{exam}', [ExamController::class, 'destroy'])->name('exams.destroy');
        Route::post('/exams/{exam}/status', [ExamController::class, 'setStatus'])->name('exams.status');

        Route::get('/exams/{exam}/setup', [ExamSetupController::class, 'show'])->name('exams.setup');
        Route::post('/exams/{exam}/setup/subject', [ExamSetupController::class, 'saveSubject'])->name('exams.setup.subject');
        Route::post('/exams/{exam}/setup/apply', [ExamSetupController::class, 'applyToClasses'])->name('exams.setup.apply');
        Route::post('/exams/{exam}/setup/fill', [ExamSetupController::class, 'fillClass'])->name('exams.setup.fill');
        Route::post('/exams/{exam}/setup/copy', [ExamSetupController::class, 'copyFromExam'])->name('exams.setup.copy');

        Route::post('/grading-scales', [ExamSettingsController::class, 'storeScale'])->name('grading-scales.store');
        Route::put('/grading-scales/{scale}', [ExamSettingsController::class, 'updateScale'])->name('grading-scales.update');
        Route::post('/grading-scales/{scale}/default', [ExamSettingsController::class, 'makeDefaultScale'])->name('grading-scales.default');
        Route::delete('/grading-scales/{scale}', [ExamSettingsController::class, 'destroyScale'])->name('grading-scales.destroy');

        Route::post('/exam-component-types', [ExamSettingsController::class, 'storeType'])->name('exam-types.store');
        Route::put('/exam-component-types/{type}', [ExamSettingsController::class, 'updateType'])->name('exam-types.update');
        Route::delete('/exam-component-types/{type}', [ExamSettingsController::class, 'destroyType'])->name('exam-types.destroy');
    });

    // Roles & permissions
    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    // Website management
    Route::prefix('website-management')->name('website-management.')->middleware('can:website.manage')->group(function () {
        Route::get('/settings', [WebsiteSettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [WebsiteSettingsController::class, 'update'])->name('settings.update');

        Route::get('/home-page', [WebsiteHeroController::class, 'edit'])->name('home-page');
        Route::post('/home-page', [WebsiteHeroController::class, 'update'])->name('home-page.update');

        Route::get('/about-school', [WebsiteAboutController::class, 'edit'])->name('about-school');
        Route::post('/about-school', [WebsiteAboutController::class, 'update'])->name('about-school.update');

        Route::get('/events', [WebsiteEventController::class, 'index'])->name('events');
        Route::post('/events', [WebsiteEventController::class, 'store'])->name('events.store');
        Route::post('/events/{event}', [WebsiteEventController::class, 'update'])->name('events.update');
        Route::delete('/events/{event}', [WebsiteEventController::class, 'destroy'])->name('events.destroy');

        Route::get('/contact-info', [WebsiteContactController::class, 'edit'])->name('contact-info');
        Route::post('/contact-info', [WebsiteContactController::class, 'update'])->name('contact-info.update');

        Route::get('/messages', [ContactMessageController::class, 'index'])->name('messages');
        Route::post('/messages/{message}/read', [ContactMessageController::class, 'markRead'])->name('messages.mark-read');
        Route::post('/messages/{message}/unread', [ContactMessageController::class, 'markUnread'])->name('messages.mark-unread');
        Route::post('/messages/{message}/reply', [ContactMessageController::class, 'reply'])->name('messages.reply');
        Route::delete('/messages/{message}', [ContactMessageController::class, 'destroy'])->name('messages.destroy');

        Route::get('/notices', [NoticeController::class, 'index'])->name('notices');
        Route::post('/notices', [NoticeController::class, 'store'])->name('notices.store');
        Route::post('/notices/{notice}', [NoticeController::class, 'update'])->name('notices.update');
        Route::delete('/notices/{notice}', [NoticeController::class, 'destroy'])->name('notices.destroy');
        Route::post('/notices/{notice}/toggle-featured', [NoticeController::class, 'toggleFeatured'])->name('notices.toggle-featured');

        Route::get('/teachers-staff', [TeacherController::class, 'index'])->name('teachers-staff');
        Route::post('/teachers-staff', [TeacherController::class, 'store'])->name('teachers-staff.store');
        Route::post('/teachers-staff/{teacher}', [TeacherController::class, 'update'])->name('teachers-staff.update');
        Route::delete('/teachers-staff/{teacher}', [TeacherController::class, 'destroy'])->name('teachers-staff.destroy');

        Route::inertia('/gallery', 'WebsiteManagement/Gallery')->name('gallery');
    });

    // Events (staff view)
    Route::get('/events', [WebsiteEventController::class, 'index'])->middleware('can:website.manage')->name('website.events.index');

    // Gallery
    Route::middleware('can:gallery.manage')->group(function () {
        Route::get('/gallery', [GalleryController::class, 'index'])->name('galleries.index');
        Route::post('/gallery', [GalleryController::class, 'store'])->name('galleries.store');
        Route::put('/gallery/{gallery}', [GalleryController::class, 'update'])->name('galleries.update');
        Route::delete('/gallery/{gallery}', [GalleryController::class, 'destroy'])->name('galleries.destroy');
    });

    // ========================================================
    // CLASSES
    // ========================================================
    Route::middleware('can:classes.manage')->group(function () {
        Route::get('/class-settings', [ClassSettingsController::class, 'index'])->name('class-settings.index');
        Route::post('/class-settings/{kind}', [ClassSettingsController::class, 'store'])->whereIn('kind', ['shifts', 'mediums'])->name('class-settings.store');
        Route::put('/class-settings/{kind}/{id}', [ClassSettingsController::class, 'update'])->whereIn('kind', ['shifts', 'mediums'])->name('class-settings.update');
        Route::delete('/class-settings/{kind}/{id}', [ClassSettingsController::class, 'destroy'])->whereIn('kind', ['shifts', 'mediums'])->name('class-settings.destroy');
        Route::get('/classes/create', [SchoolClassController::class, 'create'])->name('classes.create');
        Route::post('/classes', [SchoolClassController::class, 'store'])->name('classes.store');
        Route::get('/classes/{class}/edit', [SchoolClassController::class, 'edit'])->name('classes.edit');
        Route::put('/classes/{class}', [SchoolClassController::class, 'update'])->name('classes.update');
        Route::delete('/classes/{class}', [SchoolClassController::class, 'destroy'])->name('classes.destroy');

        Route::post('/classesmanagement', [ClassesmanagementController::class, 'store'])->name('classesmanagement.store');
        Route::put('/classesmanagement/{class}', [ClassesmanagementController::class, 'update'])->name('classesmanagement.update');
        Route::delete('/classesmanagement/{class}', [ClassesmanagementController::class, 'destroy'])->name('classesmanagement.destroy');

        // Subject teachers (who teaches what, per class and year)
        Route::get('/subject-teachers', [SubjectTeacherController::class, 'index'])->name('subject-teachers.index');
        Route::post('/subject-teachers', [SubjectTeacherController::class, 'assign'])->name('subject-teachers.assign');
        Route::get('/subject-teachers/{class}/history', [SubjectTeacherController::class, 'history'])->name('subject-teachers.history');
    });

    Route::middleware('can:classes.view')->group(function () {
        Route::get('/classes', [SchoolClassController::class, 'index'])->name('classes.index');
        Route::get('/classes/{class}', [SchoolClassController::class, 'show'])->name('classes.show');
        Route::get('/classesmanagement', [ClassesmanagementController::class, 'index'])->name('classesmanagement.index');
    });

    // ========================================================
    // ATTENDANCE
    // ========================================================
    Route::post('/attendance/store', [AttendanceController::class, 'store'])->middleware('can:attendance.take')->name('attendance.store');

    Route::middleware('can:attendance.view')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('/attendance/class/{classId}', [AttendanceController::class, 'show'])->name('attendance.show');
        Route::get('/attendance/{class}/records', [AttendanceController::class, 'records'])->name('attendance.records');
    });

    // ========================================================
    // RESULTS
    // ========================================================
    Route::middleware('can:results.view')->group(function () {
        Route::get('/results', [ResultInputController::class, 'index'])->name('results.index');
        Route::get('/results/class/{classId}', [ResultInputController::class, 'show'])->name('results.show');
    });

    Route::middleware('can:results.compile')->group(function () {
        Route::get('/student-results', [StudentResultController::class, 'index'])->name('student.results');
        Route::get('/tabulation/class/{class}', [TabulationController::class, 'show'])->name('tabulation.show');
        Route::get('/reports/marksheet/student/{student}', [ReportController::class, 'marksheet'])->name('reports.marksheet.student');
        Route::get('/reports/marksheet/class/{class}', [ReportController::class, 'classMarksheets'])->name('reports.marksheet.class');
        Route::get('/reports/summary/class/{class}', [ReportController::class, 'classSummary'])->name('reports.summary.class');
        Route::get('/reports/class-wise', [ReportController::class, 'classWise'])->name('reports.class-wise');
        Route::get('/student-results/class/{classId}', [StudentResultController::class, 'show'])->name('student.results.show');
    });

    Route::post('/results/class/{classId}', [ResultInputController::class, 'store'])->middleware('can:results.enter')->name('results.store');

    Route::post('/results/class/{classId}/lock', [ResultInputController::class, 'lock'])->middleware('can:results.lock')->name('results.lock');
    Route::post('/results/class/{classId}/unlock', [ResultInputController::class, 'unlock'])->middleware('can:results.unlock')->name('results.unlock');

    // ========================================================
    // YEARLY PROMOTION (shift students to next year)
    // ========================================================
    Route::middleware('can:promotion.run')->group(function () {
        Route::post('/student-results/shift', [StudentResultController::class, 'shift'])->name('student.results.shift');
        Route::get('/student-results/shift-status', [StudentResultController::class, 'shiftStatus'])->name('student.results.shift-status');
        Route::delete('/student-results/reset-shift/{recordId}', [StudentResultController::class, 'resetShift'])->name('student.results.reset-shift');
    });

    // ACADEMIC CALENDAR (who may do what is checked in the controller)
    Route::prefix('calendar')->name('calendar.')->controller(\App\Http\Controllers\CalendarController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/upcoming', 'upcoming')->name('upcoming');
        Route::get('/print', 'print')->middleware('throttle:30,1')->name('print');
        Route::post('/events', 'store')->name('events.store');
        Route::put('/events/{event}', 'update')->name('events.update');
        Route::delete('/events/{event}', 'destroy')->name('events.destroy');
        Route::post('/events/{event}/move', 'move')->name('events.move');
        Route::post('/events/{event}/confirm', 'confirm')->name('events.confirm');
        Route::post('/bulk/preview', 'bulkPreview')->middleware('throttle:30,1')->name('bulk.preview');
        Route::post('/bulk', 'bulkStore')->middleware('throttle:20,1')->name('bulk.store');
        Route::get('/bulk/template', 'template')->name('bulk.template');
        Route::post('/copy/preview', 'copyPreview')->middleware('throttle:30,1')->name('copy.preview');
        Route::post('/copy', 'copyStore')->middleware('throttle:10,1')->name('copy.store');
        Route::post('/closure', 'closeSchool')->middleware('throttle:20,1')->name('closure');
        Route::put('/off-days', 'offDays')->name('off-days');
    });

    // TEACHING PLAN (syllabus, yearly plans, daily log, progress)
    require __DIR__ . '/teaching.php';

    // ========================================================
    // ROUTINE (class timetables)
    // ========================================================
    Route::prefix('routine')->name('routine.')->group(function () {
        Route::get('/', [RoutineController::class, 'index'])->name('index');
        Route::get('/periods', [RoutineController::class, 'periods'])->name('periods');
        Route::put('/periods', [RoutineController::class, 'savePeriods'])->name('periods.save');
        Route::delete('/periods/shifts/{shift}', [RoutineController::class, 'resetPeriods'])->whereNumber('shift')->name('periods.reset');
        Route::put('/days', [RoutineController::class, 'saveDays'])->name('days.save');
        Route::get('/teachers', [RoutineController::class, 'teachers'])->name('teachers');
        Route::get('/teachers/{teacher}/print', [RoutineController::class, 'printTeacher'])->whereNumber('teacher')->name('teachers.print');

        Route::get('/classes/{class}', [RoutineController::class, 'editor'])->whereNumber('class')->name('editor');
        Route::get('/classes/{class}/print', [RoutineController::class, 'print'])->whereNumber('class')->name('print');
        Route::put('/classes/{class}/cell', [RoutineController::class, 'cell'])->whereNumber('class')->middleware('throttle:240,1')->name('cell');
        Route::put('/classes/{class}/cells', [RoutineController::class, 'cells'])->whereNumber('class')->name('cells');
        Route::post('/classes/{class}/copy-day', [RoutineController::class, 'copyDay'])->whereNumber('class')->name('copy-day');
        Route::post('/classes/{class}/copy-from', [RoutineController::class, 'copyFrom'])->whereNumber('class')->name('copy-from');
        Route::delete('/classes/{class}/cells', [RoutineController::class, 'clear'])->whereNumber('class')->name('clear');
        Route::put('/classes/{class}/periods', [RoutineController::class, 'saveClassPeriods'])->whereNumber('class')->name('class-periods.save');
        Route::delete('/classes/{class}/periods', [RoutineController::class, 'resetClassPeriods'])->whereNumber('class')->name('class-periods.reset');
        Route::put('/classes/{class}/plan', [RoutineController::class, 'savePlan'])->whereNumber('class')->name('plan.save');
        Route::post('/classes/{class}/auto', [RoutineController::class, 'autoPreview'])->whereNumber('class')->middleware('throttle:60,1')->name('auto.preview');
        Route::post('/classes/{class}/auto/apply', [RoutineController::class, 'autoApply'])->whereNumber('class')->middleware('throttle:30,1')->name('auto.apply');
        Route::post('/classes/{class}/publish', [RoutineController::class, 'publish'])->whereNumber('class')->name('publish');
        Route::delete('/classes/{class}/publish', [RoutineController::class, 'unpublish'])->whereNumber('class')->name('unpublish');
    });

    // ========================================================
    // STUDENTS
    // ========================================================
    Route::middleware('can:students.create')->group(function () {
        Route::get('/students/create', fn () => redirect()->route('sm.students.create'))->name('students.create');
        Route::get('/students/generate-id', [StudentController::class, 'generateId'])->name('students.generate-id');
        Route::post('/students', [StudentController::class, 'store'])->name('students.store');
    });

    Route::middleware('can:students.view')->group(function () {
        Route::get('/students', fn () => redirect()->route('sm.students.index'))->name('students.index');
        Route::get('/students/{student}', fn (\App\Models\Student $student) => redirect()->route('sm.students.show', $student->profile_id))->whereNumber('student')->name('students.show');
        Route::get('/studentmanagement', fn () => redirect()->route('sm.students.index'))->name('studentmanagement');
    });

    Route::middleware('can:students.edit')->group(function () {
        Route::get('/students/{student}/edit', fn (\App\Models\Student $student) => redirect()->route('sm.students.edit', $student->profile_id))->whereNumber('student')->name('students.edit');
        Route::put('/students/{student}', [StudentController::class, 'update'])->name('students.update');
        Route::put('/studentmanagement/{student}', [StudentManagementController::class, 'update'])->name('studentmanagement.update');
        Route::post('/studentmanagement/{student}', [StudentManagementController::class, 'update'])->name('studentmanagement.update.post');
        Route::post('/students/{student}/status', [StudentStatusController::class, 'update'])->name('students.status');
    });

    Route::middleware('can:students.delete')->group(function () {
        Route::delete('/students/{student}', [StudentController::class, 'destroy'])->name('students.destroy');
        Route::delete('/studentmanagement/{student}', [StudentManagementController::class, 'destroy'])->name('studentmanagement.destroy');
    });

    // ========================================================
    // STUDENT MODULE  (/student-module/…)
    // Every sensitive rule is enforced in App\Services\StudentRegistry and by the can: permission of each route.
    // ========================================================
    Route::prefix('student-module')->name('sm.')->group(function () {
        // the two entry forms (declared before /students/{profile} so "create" and "quick" are not read as ids)
        Route::middleware('can:students.create')->group(function () {
            Route::get('/students/create', [StudentModuleController::class, 'create'])->name('students.create');
            Route::post('/students', [StudentModuleController::class, 'store'])->name('students.store');
            Route::get('/students/quick', [StudentModuleController::class, 'quick'])->name('students.quick');
            Route::post('/students/quick', [StudentModuleController::class, 'quickStore'])->name('students.quick.store');
        });

        Route::middleware('can:students.view')->group(function () {
            Route::get('/students', [StudentModuleController::class, 'index'])->name('students.index');
            Route::get('/students/{profile}', [StudentModuleController::class, 'show'])->whereNumber('profile')->name('students.show');
        });

        Route::middleware('can:students.edit')->group(function () {
            Route::get('/students/{profile}/edit', [StudentModuleController::class, 'edit'])->whereNumber('profile')->name('students.edit');
            Route::put('/students/{profile}', [StudentModuleController::class, 'update'])->whereNumber('profile')->name('students.update');
        });

        Route::delete('/students/{profile}', [StudentModuleController::class, 'destroy'])->whereNumber('profile')->middleware('can:students.delete')->name('students.destroy');

        // finished exports: the status page and the file
        Route::get('/exports/{task}', [TaskController::class, 'export'])->whereNumber('task')->name('exports.show');
        Route::get('/tasks/{task}/download', [TaskController::class, 'download'])->whereNumber('task')->name('tasks.download');

        // admission payments, receipts, dues
        Route::middleware('can:students.admission_payments')->prefix('admission-payments')->name('admission-payments.')->group(function () {
            Route::get('/', [AdmissionPaymentController::class, 'index'])->name('index');
            Route::get('/find', [AdmissionPaymentController::class, 'find'])->name('find');
            Route::get('/export', [AdmissionPaymentController::class, 'export'])->name('export');
            Route::post('/fees', [AdmissionPaymentController::class, 'saveFees'])->name('fees');
            Route::post('/', [AdmissionPaymentController::class, 'store'])->middleware('throttle:60,1')->name('store');
            Route::post('/{payment}/void', [AdmissionPaymentController::class, 'void'])->whereNumber('payment')->name('void');
            Route::get('/{payment}/receipt', [AdmissionPaymentController::class, 'receipt'])->whereNumber('payment')->name('receipt');
        });

        // certificate register (issuing needs its own permission, checked in the controller)
        Route::middleware('can:students.view_certificates')->prefix('certificates')->name('certificates.')->group(function () {
            Route::get('/', [CertificateController::class, 'index'])->name('index');
            Route::post('/', [CertificateController::class, 'store'])->name('store');
            Route::post('/{certificate}/void', [CertificateController::class, 'void'])->whereNumber('certificate')->name('void');
            Route::get('/{certificate}/print', [CertificateController::class, 'print'])->whereNumber('certificate')->name('print');
        });

        // transfers: each step's permission is enforced in TransferService
        Route::prefix('transfers')->name('transfers.')->group(function () {
            Route::get('/', [TransferController::class, 'index'])->name('index');
            Route::post('/', [TransferController::class, 'store'])->name('store');
            Route::post('/{transfer}/recheck', [TransferController::class, 'recheck'])->whereNumber('transfer')->name('recheck');
            Route::post('/{transfer}/approve', [TransferController::class, 'approve'])->whereNumber('transfer')->name('approve');
            Route::post('/{transfer}/reject', [TransferController::class, 'reject'])->whereNumber('transfer')->name('reject');
            Route::post('/{transfer}/cancel', [TransferController::class, 'cancel'])->whereNumber('transfer')->name('cancel');
        });
        Route::post('/students/{profile}/reinstate', [TransferController::class, 'reinstate'])->whereNumber('profile')->name('students.reinstate');

        // student subjects (class-scoped permission checked in the service)
        Route::get('/subjects', [StudentSubjectController::class, 'index'])->name('subjects.index');
        Route::put('/subjects/students/{student}', [StudentSubjectController::class, 'update'])->whereNumber('student')->name('subjects.update');
        Route::post('/subjects/classes/{class}/bulk', [StudentSubjectController::class, 'bulk'])->whereNumber('class')->name('subjects.bulk');

        // student report and statistics
        Route::middleware('can:students.reports')->group(function () {
            Route::get('/reports', [StudentReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/print', [StudentReportController::class, 'print'])->name('reports.print');
            Route::get('/reports/export', [StudentReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');
            Route::get('/statistics', [StudentReportController::class, 'statistics'])->name('statistics.index');
            Route::get('/statistics/print', [StudentReportController::class, 'statisticsPrint'])->name('statistics.print');
        });

        // online applications
        Route::middleware('can:students.online_admission')->prefix('online-admissions')->name('online-admissions.')->group(function () {
            Route::get('/', [OnlineAdmissionController::class, 'index'])->name('index');
            Route::post('/{application}/approve', [OnlineAdmissionController::class, 'approve'])->whereNumber('application')->name('approve');
            Route::post('/{application}/reject', [OnlineAdmissionController::class, 'reject'])->whereNumber('application')->name('reject');
        });

        // promotion to the next session: automatic (preview, then confirm) and manual (student by student)
        Route::middleware('can:students.promote_students')->prefix('promotion')->name('promotion.')->group(function () {
            Route::get('/auto', [PromotionController::class, 'auto'])->name('auto');
            Route::post('/auto/prepare', [PromotionController::class, 'prepare'])->name('prepare');
            Route::post('/auto', [PromotionController::class, 'run'])->name('run');
            Route::get('/manual', [PromotionController::class, 'manual'])->name('manual');
            Route::post('/manual', [PromotionController::class, 'applyManual'])->name('manual.apply');
        });

        // import from Excel / CSV: upload → preview → confirm. Needs its own permission; the upload is rate-limited
        Route::middleware('can:students.import_students')->prefix('import')->name('import.')->group(function () {
            Route::get('/', [ImportController::class, 'index'])->name('index');
            Route::get('/template', [ImportController::class, 'template'])->name('template');
            Route::post('/', [ImportController::class, 'upload'])->middleware('throttle:10,1')->name('upload');
            Route::get('/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('show');
            Route::post('/{import}/confirm', [ImportController::class, 'confirm'])->whereNumber('import')->name('confirm');
            Route::post('/{import}/cancel', [ImportController::class, 'cancel'])->whereNumber('import')->name('cancel');
            Route::get('/{import}/report', [ImportController::class, 'report'])->whereNumber('import')->name('report');
        });

        // photos (single and bulk); the upload routes are rate-limited
        Route::middleware('can:students.manage_images')->group(function () {
            Route::get('/images', [PhotoController::class, 'index'])->name('images.index');
            Route::get('/images/find', [PhotoController::class, 'find'])->name('images.find');
            Route::post('/images/bulk', [PhotoController::class, 'storeBulk'])->middleware('throttle:20,1')->name('images.bulk');
            Route::post('/images/{profile}', [PhotoController::class, 'storeOne'])->whereNumber('profile')->middleware('throttle:60,1')->name('images.store');
        });

        // progress and the report of a background task (only its owner, or someone who manages users, may read it)
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::get('/tasks/{task}/report', [TaskController::class, 'report'])->name('tasks.report');

        // change requests: who may see the page and who may decide is checked in the controller / service
        Route::get('/change-requests', [ChangeRequestController::class, 'index'])->name('change-requests.index');
        Route::post('/change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])->name('change-requests.approve');
        Route::post('/change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])->name('change-requests.reject');
    });

    // ========================================================
    // FEES & FINANCE
    // ========================================================
    Route::middleware('can:fees.manage_structures')->group(function () {
        Route::get('/fees/create', [FeeController::class, 'create'])->name('fees.create');
        Route::post('/fees/create', [FeeController::class, 'store'])->name('fees.create.store');
        Route::delete('/fees/destroy-group', [FeeController::class, 'destroyGroup'])->name('fees.destroy-group');
    });

    Route::middleware('can:fees.view')->group(function () {
        Route::get('/fees', [CollectFeesController::class, 'index'])->name('fees.index');
        Route::get('/fees/class/{classId}', [CollectFeesController::class, 'show'])->name('fees.show');
    });

    Route::post('/fees/policy/save', [CollectFeesController::class, 'savePolicy'])->middleware('can:fees.discount')->name('fees.policy.save');
    Route::post('/fees/collect-payment', [CollectFeesController::class, 'collectPayment'])->middleware('can:fees.collect')->name('fees.collect.payment');
});

// ============================================================
// ANY LOGGED-IN USER
// ============================================================
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
