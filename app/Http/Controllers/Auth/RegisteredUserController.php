<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function create()
    {
        return inertia('Auth/Register');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // ✅ প্রথম user = admin, বাকি সবাই user
        $isFirstUser = User::count() === 0;

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $isFirstUser ? User::ROLE_ADMIN : User::ROLE_USER,
            'status'   => User::STATUS_ACTIVE,
        ]);

        event(new Registered($user));

        Auth::login($user);

        // ✅ Role অনুযায়ী redirect
        if ($user->isStaff()) {
            return redirect('/dashboard');   // admin + teacher
        }

        return redirect('/');                // normal user → home
    }
}