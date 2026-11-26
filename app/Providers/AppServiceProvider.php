<?php

namespace App\Providers;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // one calendar per request, so its days are worked out once
        $this->app->scoped(\App\Services\AcademicCalendar::class);
        $this->app->scoped(\App\Services\Teaching\Calendar::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Every ability in config/abilities.php becomes a Gate, so routes can use
        // ->middleware('can:fees.collect') and code can use $user->can('fees.collect', $class).
        foreach (config('abilities.groups', []) as $abilities) {
            foreach (array_keys($abilities) as $ability) {
                Gate::define($ability, fn (User $user, SchoolClass|int|null $class = null) => $user->canDo($ability, $class));
            }
        }
    }
}
