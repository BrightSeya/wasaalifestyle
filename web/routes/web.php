<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
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
    Route::post('/activities/{activity}/log', [ActivityController::class, 'log'])->name('activities.log');

    Route::get('/settings', [ProfileController::class, 'edit'])->name('settings.edit');
    Route::put('/settings/profile', [ProfileController::class, 'update'])->name('settings.profile');
    Route::put('/settings/preferences', [PreferencesController::class, 'update'])->name('settings.preferences');

    Route::get('/recommendations', [RecommendationController::class, 'index'])->name('recommendations.index');
    Route::post('/activities/{activity}/recommendations', [RecommendationController::class, 'store'])->name('recommendations.store');
    Route::post('/recommendations/{recommendation}/accept', [RecommendationController::class, 'accept'])->name('recommendations.accept');
    Route::post('/recommendations/{recommendation}/dismiss', [RecommendationController::class, 'dismiss'])->name('recommendations.dismiss');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::view('/', 'admin.dashboard')->name('dashboard');
    });
});
