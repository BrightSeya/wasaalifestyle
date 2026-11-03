<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarReauthorizationRequired;
use App\Exceptions\GoogleCalendarServiceUnavailable;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GoogleCalendarOAuthService
{
    public const READONLY_EVENTS_SCOPE = 'https://www.googleapis.com/auth/calendar.events.readonly';

    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function isConfigured(): bool
    {
        return filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && filled(config('services.google_calendar.redirect_uri'));
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTHORIZATION_ENDPOINT.'?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => self::READONLY_EVENTS_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(User $user, string $code): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_ENDPOINT, [
                'code' => $code,
                'client_id' => config('services.google_calendar.client_id'),
                'client_secret' => config('services.google_calendar.client_secret'),
                'redirect_uri' => config('services.google_calendar.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            return false;
        }

        $tokens = $response->successful() ? $response->json() : null;
        if (! is_array($tokens)
            || ! is_string($tokens['access_token'] ?? null)
            || $tokens['access_token'] === ''
            || ! is_numeric($tokens['expires_in'] ?? null)
            || (int) $tokens['expires_in'] < 1
            || ! is_string($tokens['token_type'] ?? null)
            || strtolower($tokens['token_type']) !== 'bearer'
            || ! is_string($tokens['scope'] ?? null)) {
            return false;
        }

        $grantedScopes = preg_split('/\s+/', trim($tokens['scope'])) ?: [];
        $calendarScopes = array_filter($grantedScopes, fn (string $scope) => str_starts_with($scope, 'https://www.googleapis.com/auth/calendar'));
        if (! in_array(self::READONLY_EVENTS_SCOPE, $calendarScopes, true)
            || array_diff($grantedScopes, [self::READONLY_EVENTS_SCOPE]) !== []) {
            return false;
        }

        $connection = $user->googleCalendarConnection()->firstOrNew();
        $refreshToken = $tokens['refresh_token'] ?? $connection->refresh_token;
        if (! is_string($refreshToken) || $refreshToken === '') {
            return false;
        }

        $connection->fill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $refreshToken,
            'scope' => self::READONLY_EVENTS_SCOPE,
            'expires_at' => now()->addSeconds((int) $tokens['expires_in']),
        ])->save();

        return true;
    }

    public function validAccessToken(GoogleCalendarConnection $connection, bool $forceRefresh = false): string
    {
        if (! $forceRefresh && $connection->expires_at?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }

        if (! $this->isConfigured() || ! $connection->refresh_token) {
            throw new GoogleCalendarReauthorizationRequired;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_ENDPOINT, [
                'client_id' => config('services.google_calendar.client_id'),
                'client_secret' => config('services.google_calendar.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException) {
            throw new GoogleCalendarServiceUnavailable;
        }

        if ($response->json('error') === 'invalid_grant') {
            $connection->delete();
            throw new GoogleCalendarReauthorizationRequired;
        }

        $tokens = $response->successful() ? $response->json() : null;
        if (! is_array($tokens)
            || ! is_string($tokens['access_token'] ?? null)
            || $tokens['access_token'] === ''
            || ! is_numeric($tokens['expires_in'] ?? null)
            || (int) $tokens['expires_in'] < 1) {
            throw new GoogleCalendarServiceUnavailable;
        }

        $connection->access_token = $tokens['access_token'];
        if (is_string($tokens['refresh_token'] ?? null) && $tokens['refresh_token'] !== '') {
            $connection->refresh_token = $tokens['refresh_token'];
        }
        $connection->expires_at = now()->addSeconds((int) $tokens['expires_in']);
        $connection->save();

        return $connection->access_token;
    }
}
