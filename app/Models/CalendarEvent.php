<?php

namespace App\Models;

use App\Services\AcademicCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** One entry of the Academic Calendar. Read through App\Services\AcademicCalendar, which expands yearly repeats. */
class CalendarEvent extends Model
{
    /** type => [label, closes the school by default, colour name used by the screens] */
    public const TYPES = [
        'holiday'   => ['Holiday', true, 'red'],
        'vacation'  => ['Vacation', true, 'orange'],
        'closure'   => ['Emergency closure', true, 'black'],
        'makeup'    => ['Make-up day', false, 'teal'],
        'exam'      => ['Exam', false, 'violet'],
        'term'      => ['Term / semester', false, 'slate'],
        'event'     => ['School event', false, 'blue'],
        'meeting'   => ['Parent meeting', false, 'green'],
        'result'    => ['Result publication', false, 'indigo'],
        'admission' => ['Admission period', false, 'cyan'],
        'fee_due'   => ['Fee due date', false, 'amber'],
        'other'     => ['Other', false, 'grey'],
    ];

    /** only for these may the admin choose whether the school is closed */
    public const OPTIONAL_OFF = ['event', 'other', 'meeting'];

    public const AUDIENCES = ['all', 'classes', 'teachers'];

    protected $fillable = ['type', 'title', 'title_bn', 'starts_on', 'ends_on', 'audience', 'note', 'off', 'tentative', 'repeat', 'skip_years', 'follows_day', 'exam_id', 'created_by'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'off' => 'boolean', 'tentative' => 'boolean', 'skip_years' => 'array', 'follows_day' => 'integer', 'exam_id' => 'integer'];

    protected static function booted(): void
    {
        // whatever depends on dates hears of every change
        static::saved(fn (CalendarEvent $e) => app(AcademicCalendar::class)->changed($e));
        static::deleted(fn (CalendarEvent $e) => app(AcademicCalendar::class)->changed($e));
    }

    public function classIds(): array
    {
        return DB::table('calendar_event_class')->where('event_id', $this->id)->pluck('class_id')->map(fn ($i) => (int) $i)->all();
    }

    public function setClasses(array $ids): void
    {
        DB::table('calendar_event_class')->where('event_id', $this->id)->delete();
        DB::table('calendar_event_class')->insert(array_map(fn ($id) => ['event_id' => $this->id, 'class_id' => (int) $id], array_values(array_unique($ids))));
        app(AcademicCalendar::class)->changed($this);
    }

    public function auditLabel(): string
    {
        return "Calendar: {$this->title} ({$this->starts_on?->toDateString()})";
    }
}
