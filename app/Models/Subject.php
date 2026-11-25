<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A subject in the school's master list (Bangla, Mathematics, ICT, …).
 * Classes pick their subjects from this list through `class_subjects`.
 */
class Subject extends Model
{
    protected $fillable = ['name', 'code', 'short_name', 'description', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // Until marks are stored by subject id, marks and subject-teacher rows still carry the
        // subject NAME. Renaming a subject must therefore rename those too, or they would be orphaned.
        static::updated(function (Subject $subject) {
            if (! $subject->wasChanged('name')) {
                return;
            }

            $old = $subject->getOriginal('name');

            DB::transaction(function () use ($subject, $old) {
                DB::table('results')->where('subject', $old)->update(['subject' => $subject->name]);
                DB::table('class_subject_teachers')->where('subject', $old)->update(['subject' => $subject->name]);
            });
        });
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_subjects', 'subject_id', 'class_id')
            ->withPivot(['sort_order', 'is_optional']);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Trim and collapse inner whitespace so "  Social   Science " == "Social Science". */
    public static function normalize(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * The subject with this name (case-insensitive), creating it when it does not exist yet.
     * This is what keeps "math" / "Math" / " MATH " from becoming three subjects.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = static::normalize($name);

        return static::query()->where('name', $name)->first()
            ?? static::create([
                'name'       => $name,
                'sort_order' => (int) static::query()->max('sort_order') + 1,
                'is_active'  => true,
            ]);
    }
}
