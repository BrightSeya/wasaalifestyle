<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePreferencesRequest;
use Illuminate\Http\RedirectResponse;

class PreferencesController extends Controller
{
    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $request->user()->update(['preferences' => $request->preferences()->toArray()]);

        return redirect()->route('settings.edit')->with('status', 'Preferences updated.');
    }
}
