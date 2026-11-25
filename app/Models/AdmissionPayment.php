<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money received for an admission. Never deleted: a wrong one is voided and keeps its receipt number. */
class AdmissionPayment extends Model
{
    public const METHODS = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'bank' => 'Bank'];

    protected $fillable = ['receipt_no', 'student_id', 'profile_id', 'academic_year', 'amount', 'method', 'reference', 'note', 'collected_by', 'paid_at', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];

    public function enrollment()
    {
        return $this->belongsTo(Student::class, 'student_id')->withTrashed();
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function scopeValid($q)
    {
        return $q->whereNull($this->getTable() . '.voided_at');
    }
}
