<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleCalendarConnectionController;
use App\Http\Controllers\GoogleCalendarImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleDayController;
use App\Http\Controllers\ScheduleWeekController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'store'])->name('password.update');
});

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::delete('/settings/account', [ProfileController::class, 'destroyAccount'])->name('settings.account.destroy');
    Route::get('/email/verify', function (Request $request) {
        return $request->user()->hasVerifiedEmail()
            ? to_route('dashboard')
            : view('auth.verify-email');
    })->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', EmailVerificationNotificationController::class)
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

Route::middleware(['auth', 'account.active', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::get('/schedules/week', ScheduleWeekController::class)->name('schedules.week');
    Route::get('/schedules/day', ScheduleDayController::class)->name('schedules.day');
    Route::get('/schedules/google-calendar/import', [GoogleCalendarImportController::class, 'index'])->name('schedules.google-calendar.import');
    Route::post('/schedules/google-calendar/import/preview', [GoogleCalendarImportController::class, 'preview'])->name('schedules.google-calendar.preview');
    Route::post('/schedules/google-calendar/import/confirm', [GoogleCalendarImportController::class, 'confirm'])->name('schedules.google-calendar.confirm');
    Route::resource('schedules', ScheduleController::class)->except('show');
    Route::resource('activities', ActivityController::class)->except('show');
    Route::post('/activities/{activity}/log', [ActivityController::class, 'log'])->name('activities.log');

    Route::get('/settings', [ProfileController::class, 'edit'])->name('settings.edit');
    Route::put('/settings/profile', [ProfileController::class, 'update'])->name('settings.profile');
    Route::put('/settings/password', [ProfileController::class, 'updatePassword'])->name('settings.password');
    Route::put('/settings/preferences', [PreferencesController::class, 'update'])->name('settings.preferences');
    Route::get('/settings/google-calendar/connect', [GoogleCalendarConnectionController::class, 'connect'])->name('settings.google-calendar.connect');
    Route::get('/settings/google-calendar/callback', [GoogleCalendarConnectionController::class, 'callback'])->name('settings.google-calendar.callback');
    Route::delete('/settings/google-calendar', [GoogleCalendarConnectionController::class, 'disconnect'])->name('settings.google-calendar.disconnect');

    Route::get('/recommendations', [RecommendationController::class, 'index'])->name('recommendations.index');
    Route::post('/activities/{activity}/recommendations', [RecommendationController::class, 'store'])->name('recommendations.store');
    Route::post('/recommendations/{recommendation}/accept', [RecommendationController::class, 'accept'])->name('recommendations.accept');
    Route::post('/recommendations/{recommendation}/dismiss', [RecommendationController::class, 'dismiss'])->name('recommendations.dismiss');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::view('/', 'admin.dashboard')->name('dashboard');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
        Route::post('/users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->name('users.password-reset');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/analytics', AnalyticsController::class)->name('analytics');
    });
});
