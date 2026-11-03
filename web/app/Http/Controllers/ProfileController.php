<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Services\GoogleCalendarOAuthService;
use App\Support\UserPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request, GoogleCalendarOAuthService $googleCalendar): View
    {
        $preferences = UserPreferences::fromArray($request->user()->preferences);
        $calendarConnection = $request->user()->googleCalendarConnection;
        $calendarConfigured = $googleCalendar->isConfigured();

        return view('settings.edit', compact('preferences', 'calendarConnection', 'calendarConfigured'));
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email']));

        if ($request->filled('new_password')) {
            $user->password = $request->validated('new_password');
        }

        $user->save();

        return redirect()->route('settings.edit')->with('status', 'Profile updated.');
    }
}
