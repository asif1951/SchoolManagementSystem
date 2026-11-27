<?php

use App\Http\Controllers\Attendance\LeaveController;
use App\Http\Controllers\Attendance\PrintController;
use App\Http\Controllers\Attendance\RecordController;
use App\Http\Controllers\Attendance\ReportController;
use App\Http\Controllers\Attendance\SettingsController;
use App\Http\Controllers\Attendance\TakeController;
use Illuminate\Support\Facades\Route;

/*
| Attendance (included inside the signed-in group of web.php). Who may do what, and for which class, is checked in each
| controller (see App\Services\Attendance\Access).
*/

Route::prefix('attendance')->name('attendance.')->group(function () {
    Route::get('/', [TakeController::class, 'index'])->name('index');
    Route::post('/', [TakeController::class, 'store'])->name('store');

    Route::get('/records', [RecordController::class, 'index'])->name('records');
    Route::get('/register', [RecordController::class, 'register'])->name('register');
    Route::get('/today', [RecordController::class, 'today'])->name('today');
    Route::get('/student/{student}', [RecordController::class, 'student'])->name('student');

    Route::prefix('leave')->name('leave')->controller(LeaveController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::get('/students', 'students')->name('.students');
        Route::post('/{leave}/decide', 'decide')->name('.decide');
        Route::get('/{leave}/attachment', 'attachment')->name('.attachment');
    });

    Route::prefix('reports')->name('reports')->controller(ReportController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/students', 'students')->name('.students');
        Route::get('/export', 'export')->name('.export');
        Route::get('/task/{task}', 'task')->name('.task');
        Route::get('/download/{task}', 'download')->name('.download');
    });

    Route::prefix('print')->name('print.')->controller(PrintController::class)->group(function () {
        Route::get('/sheet', 'sheet')->name('sheet');
        Route::get('/register', 'register')->name('register');
        Route::get('/report', 'report')->name('report');
    });

    Route::prefix('settings')->name('settings')->controller(SettingsController::class)->group(function () {
        Route::get('/', 'index');
        Route::put('/', 'update')->name('.update');
        Route::get('/mode/impact', 'impact')->name('.impact');
        Route::post('/mode', 'mode')->name('.mode');
        Route::delete('/mode/{mode}', 'cancelMode')->name('.mode.cancel');
    });
});
