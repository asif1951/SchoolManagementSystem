<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        // Student Information
        'student_name',
        'student_id',
        'date_of_birth',
        'gender',
        'blood_group',
        'student_photo',
        
        // Academic Information
        'academic_year',
        'class',
        'section',
        'roll_number',
        'admission_date',
        'status',
        
        // Father's Information
        'father_name',
        'father_phone',
        
        // Mother's Information
        'mother_name',
        'mother_phone',
        
        // Guardian Information
        'guardian_name',
        'guardian_phone',
        'guardian_relation',
        
        // Address
        'present_address',
        'permanent_address',
    ];
}