<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A study group such as Science, Commerce or Arts. The list is fully admin-managed.
 */
class StudyGroup extends Model
{
    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function classes()
    {
        return $this->hasMany(SchoolClass::class, 'study_group_id');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'study_group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
