<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status'           => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(Request $request)
    {
        $request->validate([
            'email'    => 'required|string',          // an email address or the 8-digit ID
            'password' => 'required|string',
        ]);

        $credentials = ['email' => \App\Models\User::loginEmailFor($request->input('email')), 'password' => $request->input('password')];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Only active accounts may sign in (suspended / inactive / pending are refused).
        if (! Auth::user()->isActive()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'Your account is not active. Please contact the school administration.',
            ]);
        }

        $request->session()->regenerate();

        // ✅ Role অনুযায়ী redirect
        $user = Auth::user();

        // Everyone lands on the first page their role can open.
        $home = $user->homePath();

        return $home === '/' ? redirect('/') : redirect()->intended($home);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}