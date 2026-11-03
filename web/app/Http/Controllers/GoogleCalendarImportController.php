<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Exceptions\GoogleCalendarImportException;
use App\Exceptions\GoogleCalendarReauthorizationRequired;
use App\Exceptions\GoogleCalendarServiceUnavailable;
use App\Services\GoogleCalendarImportService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GoogleCalendarImportController extends Controller
{
    private const PREVIEW_SESSION_KEY = 'google_calendar_import_preview';

    public function index(Request $request): View
    {
        return view('schedules.google-calendar-import', [
            'connected' => $request->user()->googleCalendarConnection()->exists(),
            'dateFrom' => now()->toDateString(),
            'dateTo' => now()->addDays(6)->toDateString(),
            'categories' => Category::cases(),
        ]);
    }

    public function preview(Request $request, GoogleCalendarImportService $importer): View|RedirectResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category' => ['required', Rule::in(array_map(fn (Category $category) => $category->value, Category::cases()))],
        ]);

        $from = Carbon::createFromFormat('!Y-m-d', $validated['date_from'], config('app.timezone'));
        $to = Carbon::createFromFormat('!Y-m-d', $validated['date_to'], config('app.timezone'));
        if ($to->greaterThan($from->copy()->addDays(30))) {
            return back()->withInput()->withErrors(['date_to' => 'Choose a date range of 31 days or less.']);
        }

        if (! $request->user()->googleCalendarConnection()->exists()) {
            return to_route('settings.edit')->with('calendar_error', 'Connect Google Calendar before importing events.');
        }

        try {
            $preview = $importer->preview(
                $request->user(),
                $validated['date_from'],
                $validated['date_to'],
                $validated['category'],
            );
        } catch (GoogleCalendarReauthorizationRequired $exception) {
            return to_route('settings.edit')->with('calendar_error', $exception->getMessage());
        } catch (GoogleCalendarImportException|GoogleCalendarServiceUnavailable $exception) {
            return back()->withInput()->withErrors(['calendar_import' => $exception->getMessage()]);
        }

        $preview['preview_id'] = Str::random(48);
        $preview['user_id'] = $request->user()->id;
        $preview['created_at'] = now()->timestamp;
        $this->storePreview($request, $preview);

        return view('schedules.google-calendar-preview', [
            'preview' => $preview,
            'updatedSinceReview' => false,
        ]);
    }

    public function confirm(Request $request, GoogleCalendarImportService $importer): View|RedirectResponse
    {
        $validated = $request->validate([
            'preview_id' => ['required', 'string', 'size:48'],
        ]);

        $storedPreview = $request->session()->get(self::PREVIEW_SESSION_KEY);
        try {
            $preview = is_string($storedPreview)
                ? json_decode(Crypt::decryptString($storedPreview), true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (DecryptException|\JsonException) {
            $preview = null;
        }

        if (! is_array($preview)
            || ! hash_equals($preview['preview_id'] ?? '', $validated['preview_id'])
            || ($preview['user_id'] ?? null) !== $request->user()->id
            || ($preview['created_at'] ?? 0) < now()->subMinutes(30)->timestamp) {
            $request->session()->forget(self::PREVIEW_SESSION_KEY);

            return to_route('schedules.google-calendar.import')->withErrors([
                'calendar_import' => 'This import review expired. Please preview the date range again.',
            ]);
        }

        if (! $request->user()->googleCalendarConnection()->exists()) {
            $request->session()->forget(self::PREVIEW_SESSION_KEY);

            return to_route('settings.edit')->with('calendar_error', 'Your Google Calendar connection was removed. Reconnect before importing these events.');
        }

        try {
            $result = $importer->confirm($request->user(), $preview);
        } catch (GoogleCalendarReauthorizationRequired $exception) {
            return to_route('settings.edit')->with('calendar_error', $exception->getMessage());
        } catch (GoogleCalendarImportException|GoogleCalendarServiceUnavailable $exception) {
            return to_route('schedules.google-calendar.import')->withErrors(['calendar_import' => $exception->getMessage()]);
        }

        if (! $result['confirmed']) {
            $preview = array_merge($result['preview'], [
                'preview_id' => $validated['preview_id'],
                'user_id' => $request->user()->id,
                'created_at' => now()->timestamp,
            ]);
            $this->storePreview($request, $preview);

            return view('schedules.google-calendar-preview', [
                'preview' => $preview,
                'updatedSinceReview' => true,
            ]);
        }

        $request->session()->forget(self::PREVIEW_SESSION_KEY);

        return to_route('schedules.index')->with(
            'status',
            "Imported {$result['imported_count']} event(s); skipped {$result['duplicate_count']} duplicate(s) and {$result['unsupported_count']} unsupported event(s).",
        );
    }

    private function storePreview(Request $request, array $preview): void
    {
        $request->session()->put(
            self::PREVIEW_SESSION_KEY,
            Crypt::encryptString(json_encode($preview, JSON_THROW_ON_ERROR)),
        );
    }
}
