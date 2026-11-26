<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarReauthorizationRequired;
use App\Exceptions\GoogleCalendarServiceUnavailable;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarOAuthService
{
    public const OPENID_SCOPE = 'openid';

    public const READONLY_EVENTS_SCOPE = 'https://www.googleapis.com/auth/calendar.events.readonly';

    public const READONLY_TASKS_SCOPE = 'https://www.googleapis.com/auth/tasks.readonly';

    public const ACCOUNT_EMAIL_SCOPE = 'https://www.googleapis.com/auth/userinfo.email';

    public const REQUIRED_READONLY_SCOPES = [
        self::READONLY_EVENTS_SCOPE,
        self::READONLY_TASKS_SCOPE,
    ];

    private const AUTHORIZATION_SCOPES = [
        self::OPENID_SCOPE,
        self::READONLY_EVENTS_SCOPE,
        self::READONLY_TASKS_SCOPE,
        self::ACCOUNT_EMAIL_SCOPE,
    ];

    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const USER_INFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

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
            'scope' => implode(' ', self::AUTHORIZATION_SCOPES),
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
        } catch (ConnectionException $exception) {
            Log::warning('Google Calendar OAuth token exchange connection failed.', [
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Google Calendar OAuth token exchange failed.', [
                'http_status' => $response->status(),
                'google_error_code' => $this->googleErrorCode($response),
            ]);

            return false;
        }

        $tokens = $response->json();
        if (! is_array($tokens)
            || ! is_string($tokens['access_token'] ?? null)
            || $tokens['access_token'] === ''
            || ! is_numeric($tokens['expires_in'] ?? null)
            || (int) $tokens['expires_in'] < 1
            || ! is_string($tokens['token_type'] ?? null)
            || strtolower($tokens['token_type']) !== 'bearer'
            || ! is_string($tokens['scope'] ?? null)) {
            Log::warning('Google Calendar OAuth token response failed validation.', [
                'has_access_token' => is_array($tokens)
                    && is_string($tokens['access_token'] ?? null)
                    && $tokens['access_token'] !== '',
                'has_valid_expiry' => is_array($tokens)
                    && is_numeric($tokens['expires_in'] ?? null)
                    && (int) $tokens['expires_in'] > 0,
                'has_bearer_token_type' => is_array($tokens)
                    && is_string($tokens['token_type'] ?? null)
                    && strtolower($tokens['token_type']) === 'bearer',
                'has_scope' => is_array($tokens) && is_string($tokens['scope'] ?? null),
            ]);

            return false;
        }

        $grantedScopes = preg_split('/\s+/', trim($tokens['scope'])) ?: [];
        $missingScopes = array_diff(self::AUTHORIZATION_SCOPES, $grantedScopes);
        $unexpectedScopes = array_diff($grantedScopes, self::AUTHORIZATION_SCOPES);
        if ($missingScopes !== [] || $unexpectedScopes !== []) {
            Log::warning('Google Calendar OAuth token response contained unexpected scopes.', [
                'missing_required_scope_count' => count($missingScopes),
                'unexpected_scope_count' => count($unexpectedScopes),
                'unexpected_scopes' => array_values(array_filter(
                    $unexpectedScopes,
                    static fn ($scope): bool => is_string($scope)
                        && strlen($scope) <= 200
                        && preg_match('/\A[A-Za-z0-9._:\/-]+\z/', $scope) === 1,
                )),
            ]);

            return false;
        }

        $connection = $user->googleCalendarConnection()->firstOrNew();
        $refreshToken = $tokens['refresh_token'] ?? $connection->refresh_token;
        if (! is_string($refreshToken) || $refreshToken === '') {
            Log::warning('Google Calendar OAuth token response did not include a refresh token.');

            return false;
        }

        try {
            $userInfoResponse = Http::withToken($tokens['access_token'])
                ->timeout(10)
                ->get(self::USER_INFO_ENDPOINT);
        } catch (ConnectionException $exception) {
            Log::warning('Google Calendar OAuth account verification connection failed.', [
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        if (! $userInfoResponse->successful()) {
            Log::warning('Google Calendar OAuth account verification request failed.', [
                'http_status' => $userInfoResponse->status(),
                'google_error_code' => $this->googleErrorCode($userInfoResponse),
            ]);

            return false;
        }

        $userInfo = $userInfoResponse->json();
        $email = is_array($userInfo) ? ($userInfo['email'] ?? null) : null;
        if (! is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ($userInfo['verified_email'] ?? false) !== true) {
            Log::warning('Google Calendar OAuth account email could not be verified.', [
                'has_valid_email' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                'email_verified' => is_array($userInfo) && ($userInfo['verified_email'] ?? false) === true,
            ]);

            return false;
        }

        $connection->fill([
            'google_account_email' => $email,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', self::AUTHORIZATION_SCOPES),
            'expires_at' => now()->addSeconds((int) $tokens['expires_in']),
        ])->save();

        return true;
    }

    private function googleErrorCode(Response $response): ?string
    {
        $errorCode = $response->json('error');

        return is_string($errorCode) && preg_match('/\A[a-z_]{1,64}\z/', $errorCode) === 1
            ? $errorCode
            : null;
    }

    public function hasRequiredReadScopes(?GoogleCalendarConnection $connection): bool
    {
        if (! $connection) {
            return false;
        }

        $grantedScopes = preg_split('/\s+/', trim($connection->scope)) ?: [];

        return array_diff(self::REQUIRED_READONLY_SCOPES, $grantedScopes) === [];
    }

    public function hasAccountEmailScope(?GoogleCalendarConnection $connection): bool
    {
        if (! $connection) {
            return false;
        }

        $grantedScopes = preg_split('/\s+/', trim($connection->scope)) ?: [];

        return in_array(self::ACCOUNT_EMAIL_SCOPE, $grantedScopes, true);
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
