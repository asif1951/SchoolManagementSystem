<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The result rules of ONE academic year — everything the exam controller can change.
 * (Absent in a subject always counts as 0 / fail in that subject; that is not a setting.)
 */
class ResultPolicy extends Model
{
    public const MODE_PER_SUBJECT        = 'per_subject';          // combine each subject across exams, then grade it
    public const MODE_OVERALL_PERCENTAGE = 'overall_percentage';   // one weighted overall percentage
    public const SCOPE_EACH_EXAM         = 'each_exam';            // pass marks checked in every exam
    public const SCOPE_FINAL_ONLY        = 'final_only';           // only on the combined final result
    public const OPTIONAL_NONE           = 'none';
    public const OPTIONAL_ABOVE          = 'above_threshold';      // points above the threshold are added (BD "4th subject")

    protected $fillable = [
        'academic_year', 'calculation_mode', 'pass_rule_scope', 'require_component_pass',
        'grading_scale_id', 'optional_rule', 'optional_threshold', 'attendance_marks',
    ];

    protected $casts = [
        'require_component_pass' => 'boolean',
        'optional_threshold'     => 'decimal:2',
        'attendance_marks'       => 'decimal:2',
    ];

    public function gradingScale()
    {
        return $this->belongsTo(GradingScale::class);
    }

    /** The policy of a year; created with the defaults the first time it is needed. */
    public static function forYear(string $year): self
    {
        // Defaults are spelled out here (not left to the database) so the object returned for a
        // brand-new year already carries them.
        return static::firstOrCreate(
            ['academic_year' => $year],
            [
                'calculation_mode'       => self::MODE_PER_SUBJECT,
                'pass_rule_scope'        => self::SCOPE_FINAL_ONLY,
                'require_component_pass' => true,
                'grading_scale_id'       => GradingScale::default()->id,
                'optional_rule'          => self::OPTIONAL_ABOVE,
                'optional_threshold'     => 2.00,
                'attendance_marks'       => 0,
            ]
        );
    }

    public function scale(): GradingScale
    {
        return $this->gradingScale ?? GradingScale::default();
    }
}
