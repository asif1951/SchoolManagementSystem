<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A role is a named list of abilities (see config/abilities.php).
 * Users point at it through `users.role` = `roles.slug`.
 */
class Role extends Model
{
    public const CACHE_KEY = 'roles.map';

    protected $fillable = ['slug', 'name', 'description', 'abilities', 'is_system'];

    protected $casts = [
        'abilities' => 'array',
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Any change to roles must be visible immediately.
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * slug => abilities[] for every role, cached (read on every request).
     *
     * @return array<string, array<int,string>>
     */
    public static function abilityMap(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, fn () => static::query()
            ->pluck('abilities', 'slug')
            ->map(fn ($a) => is_array($a) ? $a : (json_decode((string) $a, true) ?: []))
            ->all());
    }
}
