<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Exceptions\GoogleCalendarImportException;
use App\Exceptions\GoogleCalendarReauthorizationRequired;
use App\Exceptions\GoogleCalendarServiceUnavailable;
use App\Services\GoogleCalendarImportService;
use App\Services\GoogleCalendarOAuthService;
use App\Support\UserTime;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GoogleCalendarImportController extends Controller
{
    private const PREVIEW_SESSION_KEY = 'google_calendar_import_preview';

    public function index(Request $request, GoogleCalendarOAuthService $googleCalendar): View
    {
        $connection = $request->user()->googleCalendarConnection;

        return view('schedules.google-calendar-import', [
            'connected' => $connection !== null,
            'tasksAuthorized' => $googleCalendar->hasRequiredReadScopes($connection),
            'dateFrom' => now(UserTime::timezone($request->user()))->toDateString(),
            'dateTo' => now(UserTime::timezone($request->user()))->addDays(6)->toDateString(),
        ]);
    }

    public function preview(Request $request, GoogleCalendarImportService $importer): View|RedirectResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $from = Carbon::createFromFormat('!Y-m-d', $validated['date_from'], UserTime::timezone($request->user()));
        $to = Carbon::createFromFormat('!Y-m-d', $validated['date_to'], UserTime::timezone($request->user()));
        if ($to->greaterThan($from->copy()->addDays(30))) {
            return back()->withInput()->withErrors(['date_to' => 'Choose a date range of 31 days or less.']);
        }

        if (! $request->user()->googleCalendarConnection()->exists()) {
            return to_route('settings.edit')->with('calendar_error', 'Connect Google Calendar before importing items.');
        }

        try {
            $preview = $importer->preview(
                $request->user(),
                $validated['date_from'],
                $validated['date_to'],
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
            'categories' => Category::cases(),
            'selectedCategories' => [],
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

            return to_route('settings.edit')->with('calendar_error', 'Your Google Calendar connection was removed. Reconnect before importing these items.');
        }

        $validator = Validator::make($request->all(), [
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['required', Rule::in(Category::values())],
        ]);
        $duplicateIds = array_fill_keys($preview['duplicate_ids'], true);
        $expectedIndexes = [];
        foreach ($preview['events'] as $index => $event) {
            if (! isset($duplicateIds[$event['google_event_id']])) {
                $expectedIndexes[] = (string) $index;
            }
        }
        $validator->after(function ($validator) use ($request, $expectedIndexes): void {
            $categories = $request->input('categories', []);
            $submittedIndexes = is_array($categories) ? array_map('strval', array_keys($categories)) : [];
            sort($submittedIndexes);
            sort($expectedIndexes);
            if ($submittedIndexes !== $expectedIndexes) {
                $validator->errors()->add('categories', 'Choose a category for every new item before importing.');
            }
        });
        if ($validator->fails()) {
            $selectedCategories = $request->input('categories', []);
            if (! is_array($selectedCategories)) {
                $selectedCategories = [];
            }

            return view('schedules.google-calendar-preview', [
                'preview' => $preview,
                'categories' => Category::cases(),
                'categoryErrors' => $validator->errors()->toArray(),
                'selectedCategories' => $selectedCategories,
                'updatedSinceReview' => false,
            ]);
        }

        $categoryChoices = $validator->validated()['categories'] ?? [];
        foreach ($preview['events'] as $index => &$event) {
            if (isset($categoryChoices[$index])) {
                $event['category'] = $categoryChoices[$index];
            }
        }
        unset($event);

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
                'categories' => Category::cases(),
                'selectedCategories' => $categoryChoices,
                'updatedSinceReview' => true,
            ]);
        }

        $request->session()->forget(self::PREVIEW_SESSION_KEY);

        return to_route('schedules.index')->with(
            'status',
            "Imported {$result['imported_count']} item(s); skipped {$result['duplicate_count']} duplicate(s) and {$result['unsupported_count']} unsupported item(s).",
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
