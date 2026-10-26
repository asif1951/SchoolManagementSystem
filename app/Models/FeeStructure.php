<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeStructure extends Model
{
    use HasFactory;

    protected $table = 'fee_structures';

    protected $fillable = [
        'class_id',
        'academic_year',
        'fee_name',
        'amount',
        'cycle',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────

    public function scopeForClass($query, int $classId)
    {
        return $query->where('class_id', $classId);
    }

    public function scopeForYear($query, string $year)
    {
        return $query->where('academic_year', $year);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /**
     * Annualized amount based on cycle.
     */
    public function getAnnualAmountAttribute(): float
    {
        $multiplier = match ($this->cycle) {
            'Monthly'     => 12,
            'Quarterly'   => 4,
            'Half-Yearly' => 2,
            'Term-wise'   => 3,
            'Yearly'      => 1,
            'One-time'    => 1,
            default       => 1,
        };

        return (float) $this->amount * $multiplier;
    }
}