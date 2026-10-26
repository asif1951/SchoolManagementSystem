<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeePayment extends Model
{
    use HasFactory;

    protected $table = 'fee_payments';

    protected $fillable = [
        'student_id',
        'academic_year',
        'amount',
        'note',
        'collected_by',
        'paid_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'paid_at'    => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function scopeForYear($query, string $year)
    {
        return $query->where('academic_year', $year);
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }
}