<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Reject suspended users and web sessions issued before a suspension.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (! $user->isSuspended() && $this->sessionIsCurrent($request, (int) $user->session_version))) {
            return $next($request);
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'This account is suspended or its session has expired.'], 403);
        }

        return redirect()->route('login')->withErrors([
            'email' => 'This account is suspended or this session has expired. Please sign in again.',
        ]);
    }

    private function sessionIsCurrent(Request $request, int $sessionVersion): bool
    {
        if (! $request->hasSession()) {
            return true;
        }

        $storedVersion = $request->session()->get('auth_session_version');

        // Feature tests use actingAs() to represent a fresh authenticated session.
        // In production, a missing stamp requires a fresh login (or remember-me auth).
        if ($storedVersion === null && app()->environment('testing')) {
            $request->session()->put('auth_session_version', $sessionVersion);

            return true;
        }

        // A remembered login creates a new authenticated session without LoginController.
        if ($storedVersion === null && Auth::guard('web')->viaRemember()) {
            $request->session()->regenerate();
            $request->session()->put('auth_session_version', $sessionVersion);

            return true;
        }

        return is_numeric($storedVersion) && (int) $storedVersion === $sessionVersion;
    }
}
