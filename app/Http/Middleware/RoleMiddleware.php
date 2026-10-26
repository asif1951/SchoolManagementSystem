<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Usage:
     *   ->middleware('role:admin')
     *   ->middleware('role:admin,teacher')
     *   ->middleware('role:admin,teacher,user')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // 1. Login check
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        // 2. Role check
        $userRole = auth()->user()->role;

        if (!in_array($userRole, $roles, true)) {
            abort(403, 'Unauthorized — You do not have permission to access this page.');
        }

        return $next($request);
    }
}