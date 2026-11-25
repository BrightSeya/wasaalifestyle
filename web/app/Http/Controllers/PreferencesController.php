<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePreferencesRequest;
use App\Support\UserPreferences;
use Illuminate\Http\RedirectResponse;

class PreferencesController extends Controller
{
    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $previousPreferences = UserPreferences::fromArray($user->preferences);
        $preferences = $request->preferences();

        $user->preferences = $preferences->toArray();
        $user->timezone = $request->validated('timezone');

        if ($request->boolean('contribute_shared_recommendations')) {
            $user->shared_recommendation_consent_at ??= now();
        } else {
            $user->shared_recommendation_consent_at = null;
        }

        if ($preferences->personalizeRecommendations && ! $previousPreferences->personalizeRecommendations) {
            $user->recommendation_personalization_consent_at = now();
        }

        $user->save();

        return redirect()->route('settings.edit')->with('status', 'Preferences updated.');
    }
}
