<?php

use App\Http\Controllers\StudentModule\TaskController;
use App\Http\Controllers\Teaching\DashboardController;
use App\Http\Controllers\Teaching\LessonController;
use App\Http\Controllers\Teaching\PeriodController;
use App\Http\Controllers\Teaching\ProgressController;
use App\Http\Controllers\Teaching\ReportController;
use App\Http\Controllers\Teaching\SettingsController;
use App\Http\Controllers\Teaching\LogController;
use App\Http\Controllers\Teaching\PlanController;
use App\Http\Controllers\Teaching\SyllabusController;
use Illuminate\Support\Facades\Route;

/*
| Teaching Plan module (included inside the signed-in group of web.php). Who may do what is checked in each controller
| (see App\Services\Teaching\Access), because most rules depend on whose record it is.
*/

Route::prefix('teaching')->name('teaching.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/notices/read', [DashboardController::class, 'read'])->name('notices.read');
    Route::get('/progress', [ProgressController::class, 'index'])->name('progress');
    Route::get('/period', [PeriodController::class, 'index'])->name('period');
    Route::get('/period/print', [PeriodController::class, 'print'])->name('period.print');

    Route::prefix('lessons')->name('lessons')->controller(LessonController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::put('/{lesson}', 'update')->name('.update');
        Route::delete('/{lesson}', 'destroy')->name('.destroy');
        Route::post('/{lesson}/copy', 'copy')->name('.copy');
        Route::get('/{lesson}/print', 'print')->name('.print');
    });

    Route::prefix('reports')->name('reports')->controller(ReportController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/print', 'print')->middleware('throttle:30,1')->name('.print');
        Route::post('/export', 'export')->middleware('throttle:10,1')->name('.export');
    });

    Route::prefix('settings')->name('settings')->controller(SettingsController::class)->group(function () {
        Route::get('/', 'index');
        Route::put('/', 'save')->name('.save');
        Route::put('/people/{user}', 'grant')->name('.grant');
    });

    // background tasks of this module (a task answers only to the person who started it)
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task')->name('tasks.show');
    Route::get('/tasks/{task}/download', [TaskController::class, 'download'])->whereNumber('task')->name('tasks.download');

    Route::prefix('plans')->name('plans')->controller(PlanController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::post('/make-mine', 'makeMine')->middleware('throttle:10,1')->name('.make-mine');
        Route::post('/schedule-all', 'scheduleAll')->middleware('throttle:10,1')->name('.schedule-all');
        Route::get('/{plan}', 'show')->name('.show');
        Route::delete('/{plan}', 'destroy')->name('.destroy');
        Route::post('/{plan}/schedule', 'schedule')->middleware('throttle:60,1')->name('.schedule');
        Route::post('/{plan}/copy', 'copy')->name('.copy');
        Route::post('/{plan}/sync', 'sync')->name('.sync');
        Route::post('/{plan}/order', 'reorder')->name('.reorder');
        Route::put('/{plan}/periods', 'periods')->name('.periods');
        Route::post('/{plan}/submit', 'submit')->name('.submit');
        Route::post('/{plan}/decide', 'decide')->name('.decide');
        Route::post('/{plan}/reviews', 'review')->name('.review');
    });
    Route::put('/plan-items/{item}', [PlanController::class, 'updateItem'])->name('items.update');
    Route::delete('/plan-items/{item}', [PlanController::class, 'destroyItem'])->name('items.destroy');

    Route::prefix('log')->name('log')->controller(LogController::class)->group(function () {
        Route::get('/', 'index');
        Route::put('/', 'save')->middleware('throttle:240,1')->name('.save');
        Route::delete('/{log}', 'destroy')->name('.destroy');
        Route::post('/{log}/copy', 'copyTo')->middleware('throttle:60,1')->name('.copy');
    });

    Route::prefix('syllabus')->name('syllabus')->group(function () {
        Route::get('/', [SyllabusController::class, 'index']);
        Route::put('/periods', [SyllabusController::class, 'periods'])->name('.periods');
        Route::post('/chapters', [SyllabusController::class, 'store'])->name('.store');
        Route::post('/chapters/order', [SyllabusController::class, 'reorder'])->name('.reorder');
        Route::put('/chapters/{chapter}', [SyllabusController::class, 'update'])->name('.update');
        Route::delete('/chapters/{chapter}', [SyllabusController::class, 'destroy'])->name('.destroy');
        Route::post('/chapters/{chapter}/restore', [SyllabusController::class, 'restore'])->whereNumber('chapter')->name('.restore');
        Route::post('/chapters/{chapter}/topics', [SyllabusController::class, 'storeTopic'])->name('.topics.store');
        Route::post('/chapters/{chapter}/topics/order', [SyllabusController::class, 'reorderTopics'])->name('.topics.reorder');
        Route::put('/topics/{topic}', [SyllabusController::class, 'updateTopic'])->name('.topics.update');
        Route::delete('/topics/{topic}', [SyllabusController::class, 'destroyTopic'])->name('.topics.destroy');
        Route::post('/topics/{topic}/restore', [SyllabusController::class, 'restoreTopic'])->whereNumber('topic')->name('.topics.restore');
        Route::post('/copy', [SyllabusController::class, 'copy'])->name('.copy');
        Route::get('/template', [SyllabusController::class, 'template'])->name('.template');
        Route::post('/import/preview', [SyllabusController::class, 'preview'])->middleware('throttle:20,1')->name('.preview');
        Route::post('/import/confirm', [SyllabusController::class, 'confirm'])->middleware('throttle:10,1')->name('.confirm');
    });
});
