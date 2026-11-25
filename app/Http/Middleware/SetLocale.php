<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Applies the language the user chose (English or Bangla); the choice is kept in the session. */
class SetLocale
{
    public const LOCALES = ['en', 'bn'];

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('locale');

        if (in_array($locale, self::LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
