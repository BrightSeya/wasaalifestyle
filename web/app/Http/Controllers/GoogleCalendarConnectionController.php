<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleCalendarConnectionController extends Controller
{
    public function connect(Request $request, GoogleCalendarOAuthService $google): RedirectResponse
    {
        if (! $google->isConfigured()) {
            return to_route('settings.edit')->with('calendar_error', 'Google Calendar is not configured on this server. Set the Google Calendar OAuth environment variables first.');
        }

        $state = Str::random(64);
        $request->session()->put('google_calendar_oauth_state', $state);

        return redirect()->away($google->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleCalendarOAuthService $google): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_calendar_oauth_state');
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || ! is_string($receivedState) || ! hash_equals($expectedState, $receivedState)) {
            return to_route('settings.edit')->with('calendar_error', 'Google Calendar authorization could not be verified. Please try connecting again.');
        }

        if ($request->query('error') === 'access_denied') {
            return to_route('settings.edit')->with('calendar_error', 'Google Calendar access was denied. No changes were made to the existing connection.');
        }

        if ($request->filled('error')) {
            return to_route('settings.edit')->with('calendar_error', 'Google Calendar authorization did not complete. Please try again.');
        }

        if (! $google->isConfigured()) {
            return to_route('settings.edit')->with('calendar_error', 'Google Calendar is not configured on this server. Please contact the administrator.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '' || ! $google->completeAuthorization($request->user(), $code)) {
            return to_route('settings.edit')->with('calendar_error', 'Google Calendar could not complete the secure connection. Please try again.');
        }

        return to_route('settings.edit')->with('status', 'Google Calendar connected with read-only event access.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->googleCalendarConnection()->delete();
        $request->session()->forget('google_calendar_oauth_state');

        return to_route('settings.edit')->with('status', 'Google Calendar disconnected and saved credentials removed.');
    }
}
