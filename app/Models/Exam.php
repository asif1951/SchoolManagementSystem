<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One exam of an academic year (1st terminal, half-yearly, annual …). */
class Exam extends Model
{
    public const STATUSES = ['draft', 'open', 'closed', 'published'];

    protected $fillable = ['academic_year', 'name', 'weight', 'sort_order', 'status', 'starts_on', 'ends_on'];

    protected $casts = [
        'weight'     => 'decimal:2',
        'sort_order' => 'integer',
        'starts_on'  => 'date',
        'ends_on'    => 'date',
    ];

    protected static function booted(): void
    {
        // an exam's dates are shown, and may be changed, in the Academic Calendar: keep its event in step
        static::saved(function (Exam $exam) {
            $event = CalendarEvent::where('exam_id', $exam->id)->first();

            if (! $exam->starts_on) {
                $event?->delete();

                return;
            }

            $data = ['type' => 'exam', 'title' => mb_substr($exam->name, 0, 150), 'starts_on' => $exam->starts_on->toDateString(), 'ends_on' => ($exam->ends_on ?? $exam->starts_on)->toDateString()];
            $event ? $event->update($data) : CalendarEvent::create($data + ['exam_id' => $exam->id]);
        });

        static::deleting(fn (Exam $exam) => CalendarEvent::where('exam_id', $exam->id)->first()?->delete());
    }

    public function papers()
    {
        return $this->hasMany(ExamPaper::class);
    }

    public function scopeForYear($q, string $year)
    {
        return $q->where('academic_year', $year);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    /** Total weight of a year's exams (should be 100 before results are published). */
    public static function weightTotal(string $year): float
    {
        return (float) static::query()->forYear($year)->sum('weight');
    }
}
