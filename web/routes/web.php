<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleDayController;
use App\Http\Controllers\ScheduleWeekController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/schedules/week', ScheduleWeekController::class)->name('schedules.week');
    Route::get('/schedules/day', ScheduleDayController::class)->name('schedules.day');
    Route::resource('schedules', ScheduleController::class)->except('show');
    Route::resource('activities', ActivityController::class)->except('show');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::view('/', 'admin.dashboard')->name('dashboard');
    });
});
