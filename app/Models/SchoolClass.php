<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'class_name',
        'section',
        'academic_year',
        'class_teacher',
        'status',
        'subjects',
        'max_students',
        'description',
    ];

    protected $casts = [
        'subjects' => 'array',
    ];
}