<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What admission costs for a class in a session. */
class AdmissionFee extends Model
{
    protected $fillable = ['academic_year', 'class_name', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];
}
