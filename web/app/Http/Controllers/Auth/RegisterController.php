<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $consentedToRecommendationPersonalization = $request->boolean('recommendation_personalization_consent');
        $preferences = UserPreferences::default()->toArray();
        $preferences['personalize_recommendations'] = $consentedToRecommendationPersonalization;

        $user = User::create([
            ...$request->safe()->only(['name', 'email', 'password']),
            'preferences' => $preferences,
            'recommendation_personalization_consent_at' => $consentedToRecommendationPersonalization ? now() : null,
        ]);
        $user->sendEmailVerificationNotification();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $user->session_version);

        return redirect()->route('verification.notice');
    }
}
