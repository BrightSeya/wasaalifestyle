<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use App\Support\UserPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
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

        if ($request->hasFile('profile_photo')) {
            $previousPhoto = $user->profile_photo_path;
            $newPhoto = $request->file('profile_photo')->store("profile-photos/{$user->id}", 'public');

            if (! $newPhoto) {
                return back()->withErrors(['profile_photo' => 'The photo could not be saved. Please try again.']);
            }

            $user->profile_photo_path = $newPhoto;
            $user->save();

            if ($previousPhoto) {
                Storage::disk('public')->delete($previousPhoto);
            }

            return redirect()->route('settings.edit')->with('status', 'Profile and photo updated.');
        }

        $photoToRemove = null;
        if ($request->boolean('remove_profile_photo') && $user->profile_photo_path) {
            $photoToRemove = $user->profile_photo_path;
            $user->profile_photo_path = null;
        }

        $user->save();

        if ($photoToRemove) {
            Storage::disk('public')->delete($photoToRemove);
        }

        return redirect()->route('settings.edit')->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'new_password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $request->user()->forceFill(['password' => $validated['new_password']])->save();

        return redirect()->route('settings.edit')->with('status', 'Password updated.');
    }

    public function destroyAccount(Request $request): RedirectResponse
    {
        $request->validate([
            'delete_current_password' => ['required', 'string', 'current_password'],
            'confirmation' => ['required', 'string', 'in:DELETE'],
        ]);

        $user = $request->user();

        $deleted = DB::transaction(function () use ($user): bool {
            if ($user->isAdmin() && User::query()
                ->where('role', 'admin')
                ->where('status', 'active')
                ->lockForUpdate()
                ->count() <= 1) {
                return false;
            }

            $user->tokens()->delete();

            DB::table(config('auth.passwords.users.table'))
                ->where('email', $user->email)
                ->delete();

            $sessionTable = config('session.table', 'sessions');
            if (config('session.driver') === 'database' && Schema::hasTable($sessionTable)) {
                DB::table($sessionTable)->where('user_id', $user->getKey())->delete();
            }

            Auth::logout();
            $user->delete();

            return true;
        });

        if (! $deleted) {
            return back()->withErrors([
                'account' => 'You cannot delete the last active administrator account.',
            ]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        return to_route('login')->with('status', 'Your account and associated data have been deleted.');
    }
}
