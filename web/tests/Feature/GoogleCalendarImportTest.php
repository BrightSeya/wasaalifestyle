<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_maps_event_time_zone_and_persists_only_after_confirmation(): void
    {
        $this->configureGoogleCalendar();
        Http::fake(['*calendar/v3/calendars/primary/events*' => Http::response(['items' => [$this->timedEvent()]])]);
        $user = User::factory()->create();
        $this->connectCalendar($user);

        $this->actingAs($user)->get(route('schedules.google-calendar.import'))
            ->assertOk()
            ->assertSee('Choose an event date range');

        $response = $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())
            ->assertOk()
            ->assertSee('Read the event')
            ->assertSee('America/Los_Angeles')
            ->assertSee('Confirm and import 1 event');

        $this->assertStringNotContainsString('Read the event', session('google_calendar_import_preview'));
        $this->assertSame(0, $user->schedules()->count());
        $previewId = $response->viewData('preview')['preview_id'];

        $this->post(route('schedules.google-calendar.confirm'), ['preview_id' => $previewId])
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('status', 'Imported 1 event(s); skipped 0 duplicate(s) and 0 unsupported event(s).');

        $schedule = $user->schedules()->sole();
        $this->assertSame('google-event-1', $schedule->google_event_id);
        $this->assertSame('America/Los_Angeles', $schedule->timezone);
        $this->assertSame('exercise', $schedule->category->value);
        $this->assertSame('medium', $schedule->priority);
        $this->assertSame('pending', $schedule->status);
        $this->assertSame('09:00', $schedule->start_datetime->copy()->setTimezone('America/Los_Angeles')->format('H:i'));
        $this->assertSame('10:30', $schedule->end_datetime->copy()->setTimezone('America/Los_Angeles')->format('H:i'));
    }

    public function test_duplicate_imports_are_detected_and_the_unique_key_prevents_reimport(): void
    {
        $this->configureGoogleCalendar();
        Http::fake(['*calendar/v3/calendars/primary/events*' => Http::response(['items' => [$this->timedEvent()]])]);
        $user = User::factory()->create();
        $this->connectCalendar($user);
        $user->schedules()->create([
            'title' => 'Already in Wasaa',
            'category' => 'work',
            'start_datetime' => now()->addDay()->setTime(12, 0),
            'end_datetime' => now()->addDay()->setTime(13, 0),
            'priority' => 'medium',
            'is_recurring' => false,
            'google_event_id' => 'google-event-1',
            'timezone' => 'America/Los_Angeles',
        ]);

        $response = $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())
            ->assertOk()
            ->assertSee('Already imported');
        $previewId = $response->viewData('preview')['preview_id'];
        $this->post(route('schedules.google-calendar.confirm'), ['preview_id' => $previewId])
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('status', 'Imported 0 event(s); skipped 1 duplicate(s) and 0 unsupported event(s).');

        $this->assertSame(1, $user->schedules()->count());
    }

    public function test_existing_schedule_conflicts_are_shown_before_confirmation_and_require_review(): void
    {
        $this->configureGoogleCalendar();
        Http::fake(['*calendar/v3/calendars/primary/events*' => Http::response(['items' => [$this->timedEvent()]])]);
        $user = User::factory()->create();
        $this->connectCalendar($user);
        $user->schedules()->create([
            'title' => 'Existing appointment',
            'category' => 'work',
            'start_datetime' => '2026-11-10 17:30:00',
            'end_datetime' => '2026-11-10 18:00:00',
            'priority' => 'medium',
            'is_recurring' => false,
        ]);

        $response = $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())
            ->assertOk()
            ->assertSee('Overlaps with: Existing appointment')
            ->assertSee('They will be imported only after you confirm this review.');

        $this->assertSame(1, $user->schedules()->count());
        $previewId = $response->viewData('preview')['preview_id'];
        $this->post(route('schedules.google-calendar.confirm'), ['preview_id' => $previewId])
            ->assertRedirect(route('schedules.index'));

        $this->assertSame(2, $user->schedules()->count());
    }

    public function test_new_conflict_after_preview_requires_a_second_confirmation(): void
    {
        $this->configureGoogleCalendar();
        Http::fake(['*calendar/v3/calendars/primary/events*' => Http::response(['items' => [$this->timedEvent()]])]);
        $user = User::factory()->create();
        $this->connectCalendar($user);

        $response = $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())->assertOk();
        $previewId = $response->viewData('preview')['preview_id'];
        $user->schedules()->create([
            'title' => 'Added while reviewing',
            'category' => 'work',
            'start_datetime' => '2026-11-10 17:30:00',
            'end_datetime' => '2026-11-10 18:00:00',
            'priority' => 'medium',
            'is_recurring' => false,
        ]);

        $this->post(route('schedules.google-calendar.confirm'), ['preview_id' => $previewId])
            ->assertOk()
            ->assertSee('Your schedule changed while this import was open.')
            ->assertSee('Overlaps with: Added while reviewing');
        $this->assertSame(1, $user->schedules()->count());

        $this->post(route('schedules.google-calendar.confirm'), ['preview_id' => $previewId])
            ->assertRedirect(route('schedules.index'));
        $this->assertSame(2, $user->schedules()->count());
    }

    public function test_all_day_and_multi_day_events_are_reported_as_unsupported(): void
    {
        $this->configureGoogleCalendar();
        Http::fake(['*calendar/v3/calendars/primary/events*' => Http::response(['items' => [
            [
                'id' => 'all-day',
                'summary' => 'All day event',
                'start' => ['date' => '2026-11-10'],
                'end' => ['date' => '2026-11-11'],
            ],
            [
                'id' => 'multi-day',
                'summary' => 'Overnight event',
                'start' => ['dateTime' => '2026-11-10T22:00:00Z', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-11-11T01:00:00Z', 'timeZone' => 'UTC'],
            ],
        ]])]);
        $user = User::factory()->create();
        $this->connectCalendar($user);

        $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())
            ->assertOk()
            ->assertSee('All-day events are not supported yet.')
            ->assertSee('Multi-day timed events are not supported yet.');

        $this->assertSame(0, $user->schedules()->count());
    }

    public function test_imported_events_are_owned_by_the_requesting_user(): void
    {
        $this->configureGoogleCalendar();
        Http::fake(['*calendar/v3/calendars/primary/events*' => Http::response(['items' => [$this->timedEvent()]])]);
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->connectCalendar($owner);
        $this->connectCalendar($otherUser);

        $response = $this->actingAs($owner)->post(route('schedules.google-calendar.preview'), $this->dateRange())->assertOk();
        $previewId = $response->viewData('preview')['preview_id'];
        $this->post(route('schedules.google-calendar.confirm'), ['preview_id' => $previewId]);

        $schedule = $owner->schedules()->sole();
        $this->assertSame($owner->id, $schedule->user_id);
        $this->assertSame(0, $otherUser->schedules()->count());
    }

    public function test_user_without_calendar_connection_cannot_fetch_events(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error');

        Http::assertNothingSent();
    }

    public function test_expired_api_access_is_refreshed_and_the_import_retries_once(): void
    {
        $this->configureGoogleCalendar();
        Http::fake([
            '*calendar/v3/calendars/primary/events*' => Http::sequence()
                ->push(['error' => 'unauthorized'], 401)
                ->push(['items' => [$this->timedEvent()]], 200),
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'renewed-calendar-token',
                'expires_in' => 3600,
            ]),
        ]);
        $user = User::factory()->create();
        $connection = $this->connectCalendar($user);

        $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())->assertOk();

        $this->assertSame('renewed-calendar-token', $connection->fresh()->access_token);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/events?')
            && $request->hasHeader('Authorization', 'Bearer renewed-calendar-token'));
        Http::assertSentCount(3);
    }

    public function test_revoked_calendar_access_returns_user_to_settings_for_reauthorization(): void
    {
        $this->configureGoogleCalendar();
        Http::fake([
            '*calendar/v3/calendars/primary/events*' => Http::response(['error' => 'unauthorized'], 401),
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $user = User::factory()->create();
        $this->connectCalendar($user);

        $this->actingAs($user)->post(route('schedules.google-calendar.preview'), $this->dateRange())
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error');

        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $user->id]);
    }

    public function test_import_form_is_available_from_schedule_list_and_guest_is_redirected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('schedules.index'))->assertSee(route('schedules.google-calendar.import'), false);
        auth()->logout();
        $this->get(route('schedules.google-calendar.import'))->assertRedirect('/login');
    }

    public function test_import_date_range_is_limited_to_31_days(): void
    {
        $this->configureGoogleCalendar();
        Http::fake();
        $user = User::factory()->create();
        $this->connectCalendar($user);

        $this->actingAs($user)->from(route('schedules.google-calendar.import'))
            ->post(route('schedules.google-calendar.preview'), $this->dateRange([
                'date_to' => '2026-12-11',
            ]))
            ->assertRedirect(route('schedules.google-calendar.import'))
            ->assertSessionHasErrors('date_to');

        Http::assertNothingSent();
    }

    private function configureGoogleCalendar(): void
    {
        config([
            'services.google_calendar.client_id' => 'test-client-id',
            'services.google_calendar.client_secret' => 'test-client-secret',
            'services.google_calendar.redirect_uri' => 'http://localhost/settings/google-calendar/callback',
        ]);
    }

    private function connectCalendar(User $user): GoogleCalendarConnection
    {
        return $user->googleCalendarConnection()->create([
            'access_token' => 'calendar-access-token',
            'refresh_token' => 'calendar-refresh-token',
            'scope' => GoogleCalendarOAuthService::READONLY_EVENTS_SCOPE,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function dateRange(array $overrides = []): array
    {
        return array_merge([
            'date_from' => '2026-11-10',
            'date_to' => '2026-11-10',
            'category' => 'exercise',
        ], $overrides);
    }

    private function timedEvent(array $overrides = []): array
    {
        return array_merge([
            'id' => 'google-event-1',
            'summary' => 'Read the event',
            'start' => ['dateTime' => '2026-11-10T09:00:00-08:00', 'timeZone' => 'America/Los_Angeles'],
            'end' => ['dateTime' => '2026-11-10T10:30:00-08:00', 'timeZone' => 'America/Los_Angeles'],
        ], $overrides);
    }
}
