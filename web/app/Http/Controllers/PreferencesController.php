<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePreferencesRequest;
use Illuminate\Http\RedirectResponse;

class PreferencesController extends Controller
{
    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $previousPreferences = $user->preferences ?? [];
        $preferences = $request->preferences();

        $user->update(['preferences' => $preferences->toArray()]);

        if ($preferences->commitmentRiskEnabled && ! ($previousPreferences['commitment_risk_enabled'] ?? false)) {
            $user->forceFill(['commitment_risk_consent_at' => now()])->save();
        }

        if ($preferences->personalizeRecommendations && ! ($previousPreferences['personalize_recommendations'] ?? false)) {
            $user->forceFill(['recommendation_personalization_consent_at' => now()])->save();
        }

        return redirect()->route('settings.edit')->with('status', 'Preferences updated.');
    }
}
