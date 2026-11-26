<?php

namespace Tests\Feature;

use App\Exceptions\GoogleCalendarReauthorizationRequired;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_READONLY_SCOPE = 'https://www.googleapis.com/auth/calendar.events.readonly';

    private const TASKS_READONLY_SCOPE = 'https://www.googleapis.com/auth/tasks.readonly';

    private const ACCOUNT_EMAIL_SCOPE = 'https://www.googleapis.com/auth/userinfo.email';

    public function test_connect_starts_oauth_with_one_time_state_and_read_only_offline_access(): void
    {
        $this->configureGoogleOAuth();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.google-calendar.connect'));

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(
            self::EVENT_READONLY_SCOPE.' '.self::TASKS_READONLY_SCOPE.' '.self::ACCOUNT_EMAIL_SCOPE,
            $query['scope'],
        );
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame(route('settings.google-calendar.callback'), $query['redirect_uri']);
        $this->assertSame(64, strlen($query['state']));
        $this->assertSame($query['state'], session('google_calendar_oauth_state'));
    }

    public function test_callback_validates_state_exchanges_code_and_encrypts_credentials_for_the_user(): void
    {
        $this->configureGoogleOAuth();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-secret',
                'refresh_token' => 'test-refresh-secret',
                'expires_in' => 3600,
                'scope' => self::EVENT_READONLY_SCOPE.' '.self::TASKS_READONLY_SCOPE.' '.self::ACCOUNT_EMAIL_SCOPE,
                'token_type' => 'Bearer',
            ]),
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'calendar-owner@example.com',
                'verified_email' => true,
            ]),
        ]);
        $user = User::factory()->create();
        $state = str_repeat('s', 64);

        $this->actingAs($user)->withSession(['google_calendar_oauth_state' => $state])
            ->get(route('settings.google-calendar.callback', ['state' => $state, 'code' => 'one-time-code']))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('status', 'Google Calendar and Tasks connected with read-only access.');

        $connection = $user->googleCalendarConnection()->firstOrFail();
        $this->assertSame('calendar-owner@example.com', $connection->google_account_email);
        $this->assertSame('test-access-secret', $connection->access_token);
        $this->assertSame('test-refresh-secret', $connection->refresh_token);
        $this->assertSame(
            self::EVENT_READONLY_SCOPE.' '.self::TASKS_READONLY_SCOPE.' '.self::ACCOUNT_EMAIL_SCOPE,
            $connection->scope,
        );
        $this->assertNotSame('test-access-secret', DB::table('google_calendar_connections')->where('user_id', $user->id)->value('access_token'));
        $this->assertNotSame('test-refresh-secret', DB::table('google_calendar_connections')->where('user_id', $user->id)->value('refresh_token'));
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code'] === 'one-time-code'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_secret'] === 'test-client-secret');
        Http::assertSent(fn ($request) => $request->url() === 'https://www.googleapis.com/oauth2/v2/userinfo'
            && $request->hasHeader('Authorization', 'Bearer test-access-secret'));

        $this->get(route('settings.google-calendar.callback', ['state' => $state, 'code' => 'replayed-code']))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error');
        Http::assertSentCount(2);
    }

    public function test_token_exchange_failure_logs_google_error_code_without_credentials(): void
    {
        $this->configureGoogleOAuth();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Sensitive response details must not be logged.',
            ], 401),
        ]);
        Log::shouldReceive('warning')->once()->with('Google Calendar OAuth token exchange failed.', [
            'http_status' => 401,
            'google_error_code' => 'invalid_client',
        ]);
        $user = User::factory()->create();
        $state = str_repeat('e', 64);

        $this->actingAs($user)->withSession(['google_calendar_oauth_state' => $state])
            ->get(route('settings.google-calendar.callback', ['state' => $state, 'code' => 'one-time-code']))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error');

        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_invalid_or_replayed_state_is_rejected_without_exchanging_a_code(): void
    {
        $this->configureGoogleOAuth();
        Http::fake();
        $user = User::factory()->create();
        $state = str_repeat('s', 64);

        $this->actingAs($user)->withSession(['google_calendar_oauth_state' => $state])
            ->get(route('settings.google-calendar.callback', ['state' => str_repeat('x', 64), 'code' => 'code']))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error');

        $this->assertDatabaseCount('google_calendar_connections', 0);
        Http::assertNothingSent();
    }

    public function test_denied_authorization_and_missing_configuration_are_reported_clearly(): void
    {
        $this->configureGoogleOAuth();
        Http::fake();
        $user = User::factory()->create();
        $state = str_repeat('d', 64);

        $this->actingAs($user)->withSession(['google_calendar_oauth_state' => $state])
            ->get(route('settings.google-calendar.callback', ['state' => $state, 'error' => 'access_denied']))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error', 'Google Calendar access was denied. No changes were made to the existing connection.');

        config(['services.google_calendar.client_id' => null, 'services.google_calendar.client_secret' => null]);
        $this->get(route('settings.google-calendar.connect'))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error');
        Http::assertNothingSent();
    }

    public function test_callback_does_not_save_tokens_when_google_account_email_cannot_be_verified(): void
    {
        $this->configureGoogleOAuth();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-secret',
                'refresh_token' => 'test-refresh-secret',
                'expires_in' => 3600,
                'scope' => self::EVENT_READONLY_SCOPE.' '.self::TASKS_READONLY_SCOPE.' '.self::ACCOUNT_EMAIL_SCOPE,
                'token_type' => 'Bearer',
            ]),
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'unverified@example.com',
                'verified_email' => false,
            ]),
        ]);
        $user = User::factory()->create();
        $state = str_repeat('v', 64);

        $this->actingAs($user)->withSession(['google_calendar_oauth_state' => $state])
            ->get(route('settings.google-calendar.callback', ['state' => $state, 'code' => 'one-time-code']))
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('calendar_error');

        $this->assertDatabaseCount('google_calendar_connections', 0);
        Http::assertSentCount(2);
    }

    public function test_disconnect_removes_only_the_signed_in_users_credentials(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $user->googleCalendarConnection()->create($this->credentials());
        $otherUser->googleCalendarConnection()->create($this->credentials());

        $this->actingAs($user)->delete(route('settings.google-calendar.disconnect'))
            ->assertRedirect(route('settings.edit'));

        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $user->id]);
        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $otherUser->id]);
    }

    public function test_expired_access_token_is_refreshed_and_new_tokens_remain_encrypted(): void
    {
        $this->configureGoogleOAuth();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'refreshed-access-secret',
                'expires_in' => 3600,
            ]),
        ]);
        $user = User::factory()->create();
        $connection = $user->googleCalendarConnection()->create($this->credentials([
            'access_token' => 'expired-access-secret',
            'expires_at' => now()->subMinute(),
        ]));

        $token = app(GoogleCalendarOAuthService::class)->validAccessToken($connection);

        $this->assertSame('refreshed-access-secret', $token);
        $this->assertSame('existing-refresh-secret', $connection->fresh()->refresh_token);
        $this->assertTrue($connection->fresh()->expires_at->isFuture());
        $this->assertNotSame('refreshed-access-secret', DB::table('google_calendar_connections')->where('user_id', $user->id)->value('access_token'));
        Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'existing-refresh-secret');
    }

    public function test_revoked_refresh_token_removes_connection_and_requires_reauthorization(): void
    {
        $this->configureGoogleOAuth();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $user = User::factory()->create();
        $connection = $user->googleCalendarConnection()->create($this->credentials(['expires_at' => now()->subMinute()]));

        try {
            app(GoogleCalendarOAuthService::class)->validAccessToken($connection);
            $this->fail('A revoked refresh token should require a new authorization.');
        } catch (GoogleCalendarReauthorizationRequired $exception) {
            $this->assertStringContainsString('Reconnect', $exception->getMessage());
        }

        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $user->id]);
    }

    public function test_settings_page_shows_connection_state_without_rendering_tokens(): void
    {
        $user = User::factory()->create();
        $user->googleCalendarConnection()->create($this->credentials([
            'access_token' => 'never-render-this-access-token',
            'refresh_token' => 'never-render-this-refresh-token',
        ]));

        $this->actingAs($user)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Google Calendar is connected.')
            ->assertSee('Google account: calendar-owner@example.com')
            ->assertSee('Disconnect Google Calendar')
            ->assertDontSee('never-render-this-access-token')
            ->assertDontSee('never-render-this-refresh-token');
    }

    public function test_existing_calendar_connection_shows_tasks_reauthorization_prompt(): void
    {
        $user = User::factory()->create();
        $user->googleCalendarConnection()->create($this->credentials([
            'scope' => self::EVENT_READONLY_SCOPE,
        ]));

        $this->actingAs($user)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Reconnect to grant read-only Google Tasks access')
            ->assertSee('Reconnect to share the connected Google account email with Wasaa.')
            ->assertSee('Reconnect Google Calendar');
    }

    private function configureGoogleOAuth(): void
    {
        config([
            'services.google_calendar.client_id' => 'test-client-id',
            'services.google_calendar.client_secret' => 'test-client-secret',
            'services.google_calendar.redirect_uri' => route('settings.google-calendar.callback'),
        ]);
    }

    private function credentials(array $overrides = []): array
    {
        return array_merge([
            'access_token' => 'existing-access-secret',
            'refresh_token' => 'existing-refresh-secret',
            'scope' => self::EVENT_READONLY_SCOPE.' '.self::TASKS_READONLY_SCOPE.' '.self::ACCOUNT_EMAIL_SCOPE,
            'google_account_email' => 'calendar-owner@example.com',
            'expires_at' => now()->addHour(),
        ], $overrides);
    }
}
