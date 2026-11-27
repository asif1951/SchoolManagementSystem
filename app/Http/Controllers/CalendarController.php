<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AcademicCalendar;
use App\Services\CalendarEditor;
use App\Services\RoutineService;
use App\Services\TabulationBuilder;
use App\Support\BanglaDate;
use App\Support\Spreadsheet;
use App\Support\SheetStream;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/** The Academic Calendar screens. Rules live in App\Services\AcademicCalendar (reading) and CalendarEditor (changes). */
class CalendarController extends Controller
{
    public function __construct(private AcademicCalendar $calendar, private CalendarEditor $editor)
    {
    }

    private function need(Request $request, string ...$abilities): User
    {
        $user = $request->user();
        $abilities = $abilities ?: ['calendar.view', 'calendar.manage', 'calendar.close'];
        abort_unless(collect($abilities)->contains(fn ($a) => $user->canDo($a)), 403);

        return $user;
    }

    public function index(Request $request)
    {
        $user = $this->need($request);
        $today = AcademicCalendar::today();
        $view = in_array($request->input('view'), ['month', 'week', 'year', 'list'], true) ? $request->input('view') : 'month';
        $date = (string) $request->input('date', $today);
        $date = preg_match('/^(19|20)\d\d-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : $today;
        $d = Carbon::parse($date);

        [$from, $to] = match ($view) {
            // the month grid starts on a Saturday and always has six weeks
            'month' => [($a = $d->copy()->startOfMonth()->startOfWeek(Carbon::SATURDAY))->toDateString(), $a->copy()->addDays(41)->toDateString()],
            'week' => [($a = $d->copy()->startOfWeek(Carbon::SATURDAY))->toDateString(), $a->copy()->addDays(6)->toDateString()],
            default => ["{$d->year}-01-01", "{$d->year}-12-31"],
        };

        $types = array_values(array_intersect((array) $request->input('types', []), array_keys(CalendarEvent::TYPES)));
        $classId = (int) $request->input('class') ?: null;
        $manage = $user->canDo('calendar.manage');
        $classes = SchoolClass::where('academic_year', (string) $d->year)->where('status', 'active')->get()
            ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->full_label])->values();

        $closed = [];

        foreach ($this->calendar->closedDays($from, $to, $classId) as $day) {
            $closed[] = $day;
        }

        return Inertia::render('Calendar/Index', [
            'view' => $view, 'date' => $date, 'today' => $today, 'from' => $from, 'to' => $to,
            'events' => $this->calendar->eventsFor($user, $from, $to, ['types' => $types, 'class' => $classId]),
            'closed' => $closed,
            'filters' => ['types' => $types, 'class' => $classId],
            'types' => collect(CalendarEvent::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'off' => $t[1], 'color' => $t[2]])->values(),
            'optionalOff' => CalendarEvent::OPTIONAL_OFF,
            'classes' => $classes,
            'weekdays' => $this->calendar->weekdays(),
            'stats' => $this->calendar->statistics($d->year, $classId),
            'upcoming' => $this->calendar->upcoming($user, 6),
            'nextHoliday' => $this->calendar->nextHoliday($user),
            'tentative' => $manage ? CalendarEvent::where('tentative', true)->where('ends_on', '>=', Carbon::parse($today)->subMonth()->toDateString())->orderBy('starts_on')->limit(30)->get()
                ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'starts_on' => $e->starts_on->toDateString(), 'ends_on' => $e->ends_on->toDateString()]) : [],
            'exams' => $manage ? Exam::where('academic_year', 'like', $d->year . '%')->orderBy('sort_order')->get(['id', 'name']) : [],
            'can' => ['manage' => $manage, 'close' => $user->canDo('calendar.close')],
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    /** a few coming dates for the dashboards, fee and admission screens */
    public function upcoming(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isStaff(), 403);
        $types = array_values(array_intersect(explode(',', (string) $request->input('types')), array_keys(CalendarEvent::TYPES)));

        return response()->json([
            'today' => AcademicCalendar::today(),
            'now' => $this->calendar->day(AcademicCalendar::today()),
            'events' => $this->calendar->upcoming($user, 6, $types),
            'holiday' => $types ? null : $this->calendar->nextHoliday($user),
        ]);
    }

    private function eventRules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(CalendarEvent::TYPES))],
            'title' => ['required', 'string', 'max:150'], 'title_bn' => ['nullable', 'string', 'max:150'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'audience' => ['required', Rule::in(CalendarEvent::AUDIENCES)],
            'class_ids' => ['nullable', 'array', 'max:400'], 'class_ids.*' => ['integer', 'exists:classes,id'],
            'note' => ['nullable', 'string', 'max:500'], 'off' => ['boolean'], 'tentative' => ['boolean'],
            'repeat' => ['nullable', 'in:none,english,bangla'], 'follows_day' => ['nullable', 'integer', 'between:0,6'],
            'exam_id' => ['nullable', 'integer', 'exists:exams,id'],
        ];
    }

    /** closures and make-up days need their own permission */
    private function mayHandle(User $user, string $type): void
    {
        abort_unless(in_array($type, ['closure', 'makeup'], true) ? ($user->canDo('calendar.close') || $user->canDo('calendar.manage')) : $user->canDo('calendar.manage'), 403);
    }

    public function store(Request $request)
    {
        $user = $this->need($request, 'calendar.manage', 'calendar.close');
        $d = $request->validate($this->eventRules());
        $this->mayHandle($user, $d['type']);
        $this->editor->save($d, $user);

        return back()->with('success', 'The date was added.');
    }

    public function update(Request $request, CalendarEvent $event)
    {
        $user = $this->need($request, 'calendar.manage', 'calendar.close');
        $d = $request->validate($this->eventRules() + ['year' => ['nullable', 'integer', 'between:1900,2200']]);
        $this->mayHandle($user, $event->type);
        $this->mayHandle($user, $d['type']);

        if (! empty($d['year']) && $event->repeat !== 'none') {
            $this->editor->saveOccurrence($event, (int) $d['year'], $d, $user);

            return back()->with('success', "Changed for {$d['year']} only.");
        }

        // "every year": the screen holds the dates of the year that was clicked, which must not become the series' first year
        if ($event->repeat !== 'none' && ($d['repeat'] ?? 'none') !== 'none') {
            $d['starts_on'] = $event->starts_on->toDateString();
            $d['ends_on'] = $event->ends_on->toDateString();
        }

        $this->editor->save($d, $user, $event);

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, CalendarEvent $event)
    {
        $user = $this->need($request, 'calendar.manage', 'calendar.close');
        $this->mayHandle($user, $event->type);
        $year = $request->integer('year') ?: null;
        $this->editor->delete($event, $user, $year);

        return back()->with('success', $year && $event->repeat !== 'none' ? "Removed for {$year} only." : 'The date was deleted.');
    }

    public function move(Request $request, CalendarEvent $event)
    {
        $user = $this->need($request, 'calendar.manage', 'calendar.close');
        $this->mayHandle($user, $event->type);
        $d = $request->validate(['starts_on' => ['required', 'date_format:Y-m-d'], 'occurrence' => ['nullable', 'date_format:Y-m-d']]);
        $this->editor->move($event, $d['starts_on'], $user, $d['occurrence'] ?? null);

        return back()->with('success', 'Moved.');
    }

    public function confirm(Request $request, CalendarEvent $event)
    {
        $user = $this->need($request, 'calendar.manage');
        $d = $request->validate(['starts_on' => ['nullable', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d']]);
        $this->editor->confirm($event, $user, $d['starts_on'] ?? null, $d['ends_on'] ?? null);

        return back()->with('success', 'The date is confirmed. Everything that depends on it now uses it.');
    }

    // ---- many at once -------------------------------------------------------------------------------------------------

    /** pasted text or an Excel/CSV file → every row checked; nothing saved */
    public function bulkPreview(Request $request)
    {
        $this->need($request, 'calendar.manage');
        $request->validate(['text' => ['required_without:file', 'nullable', 'string', 'max:60000'], 'file' => ['nullable', 'file', 'max:5120', Rule::file()->extensions(['xlsx', 'csv', 'txt'])], 'tentative' => ['boolean']]);

        if ($request->hasFile('file')) {
            $rows = [];

            try {
                foreach (SheetStream::rows($request->file('file')->getRealPath()) as $n => $cells) {
                    $cells = array_values($cells);

                    // a heading row is recognised and skipped
                    if ($n === 1 && $this->editor->readDate((string) ($cells[0] ?? '')) === null) {
                        continue;
                    }

                    $rows[$n] = [$cells[0] ?? '', $cells[1] ?? '', $cells[2] ?? '', $cells[3] ?? '', $cells[4] ?? ''];

                    if (count($rows) > CalendarEditor::MAX_BULK) {
                        break;
                    }
                }
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        } else {
            $rows = $this->editor->linesToRows((string) $request->input('text'));
        }

        return response()->json(['rows' => $this->editor->check($rows, $request->boolean('tentative'))]);
    }

    public function bulkStore(Request $request)
    {
        $user = $this->need($request, 'calendar.manage');
        $d = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:' . CalendarEditor::MAX_BULK],
            'events.*.type' => ['required', Rule::in(array_diff(array_keys(CalendarEvent::TYPES), ['closure', 'makeup']))],
            'events.*.title' => ['required', 'string', 'max:150'], 'events.*.title_bn' => ['nullable', 'string', 'max:150'],
            'events.*.starts_on' => ['required', 'date_format:Y-m-d'], 'events.*.ends_on' => ['required', 'date_format:Y-m-d'], 'events.*.tentative' => ['boolean'],
        ]);
        $updated = 0;
        $n = $this->editor->addMany(array_map(fn ($e) => $e + ['audience' => 'all'], $d['events']), $user, $updated);
        $message = $n . ($n === 1 ? ' date was added.' : ' dates were added.');

        if ($updated) {
            $message .= " {$updated} " . ($updated === 1 ? 'date was' : 'dates were') . ' corrected to the new end date.';
        }

        return back()->with('success', $message);
    }

    public function template(Request $request)
    {
        $this->need($request, 'calendar.manage');
        $y = substr(AcademicCalendar::today(), 0, 4);

        return response(Spreadsheet::xlsx([['Date', 'End date', 'Title', 'Type', 'Title (Bangla)'],
            ["{$y}-02-21", '', 'International Mother Language Day', 'Holiday', 'আন্তর্জাতিক মাতৃভাষা দিবস'],
            ["{$y}-06-14", "{$y}-06-25", 'Summer vacation', 'Vacation', 'গ্রীষ্মের ছুটি'],
            ['১ বৈশাখ ' . ($y - 593), '', 'Pohela Boishakh', 'Holiday', 'পহেলা বৈশাখ']], 'Calendar'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="calendar-template.xlsx"',
        ]);
    }

    public function copyPreview(Request $request)
    {
        $this->need($request, 'calendar.manage');
        $d = $request->validate(['from' => ['required', 'integer', 'between:2000,2100']]);

        return response()->json(['events' => $this->editor->copyCandidates((int) $d['from'])]);
    }

    public function copyStore(Request $request)
    {
        $user = $this->need($request, 'calendar.manage');
        $d = $request->validate(['from' => ['required', 'integer', 'between:2000,2100'], 'ids' => ['required', 'array', 'min:1', 'max:' . CalendarEditor::MAX_BULK], 'ids.*' => ['integer'], 'tentative' => ['boolean']]);
        $n = $this->editor->copyYear((int) $d['from'], $d['ids'], $request->boolean('tentative'), $user);

        return back()->with('success', $n . ($n === 1 ? ' date was copied to ' : ' dates were copied to ') . ($d['from'] + 1) . '.');
    }

    public function closeSchool(Request $request)
    {
        $user = $this->need($request, 'calendar.close');
        $d = $request->validate([
            'reason' => ['required', 'string', 'max:150'], 'reason_bn' => ['nullable', 'string', 'max:150'], 'note' => ['nullable', 'string', 'max:500'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'audience' => ['required', 'in:all,classes'], 'class_ids' => ['nullable', 'array', 'max:400'], 'class_ids.*' => ['integer', 'exists:classes,id'],
            'makeup_on' => ['nullable', 'date_format:Y-m-d'], 'follows_day' => ['nullable', 'integer', 'between:0,6'], 'reschedule' => ['boolean'],
        ]);
        $r = $this->editor->close($d, $user);

        $message = 'The closure was recorded. Routine, attendance and teaching plans follow it.'
            . ($r['makeup'] ? ' The make-up day is ' . $r['makeup']->starts_on->format('d M Y') . '.' : '')
            . ($r['task'] ? " The dates of {$r['plans']} teaching plans are being spread again." : '');

        return back()->with('success', $message);
    }

    /** the weekly off days: the same setting the routine uses */
    public function offDays(Request $request, RoutineService $routines)
    {
        $this->need($request, 'calendar.manage');
        $d = $request->validate(['off' => ['present', 'array', 'max:6'], 'off.*' => ['integer', 'between:0,6']]);
        $working = array_values(array_diff(range(0, 6), array_map('intval', $d['off'])));
        $routines->setWorkingDays($working);
        $this->calendar->changed();
        \App\Models\AuditLog::record($request->user(), 'cal.offdays', ['off' => $d['off']]);

        return back()->with('success', 'The weekly off days were saved.');
    }

    public function print(Request $request, TabulationBuilder $school)
    {
        $user = $this->need($request);
        $year = max(2000, min(2100, (int) $request->input('year', substr(AcademicCalendar::today(), 0, 4))));
        $mode = in_array($request->input('calendar'), ['english', 'bangla', 'both'], true) ? $request->input('calendar') : 'both';
        $o = in_array($request->input('orientation'), ['portrait', 'landscape'], true) ? $request->input('orientation') : 'portrait';
        $info = $school->school();
        $events = $this->calendar->eventsFor($user, "{$year}-01-01", "{$year}-12-31");
        $byDay = [];

        foreach ($events as $e) {
            if ($e['type'] === 'term') {
                continue;
            }

            for ($d = Carbon::parse(max($e['starts_on'], "{$year}-01-01")); $d->toDateString() <= min($e['ends_on'], "{$year}-12-31"); $d->addDay()) {
                $byDay[$d->toDateString()][] = $e;
            }
        }

        return view('print.calendar', [
            'year' => $year, 'mode' => $mode, 'events' => $events, 'byDay' => $byDay, 'closed' => array_flip($this->calendar->closedDays("{$year}-01-01", "{$year}-12-31")),
            'stats' => $this->calendar->statistics($year), 'bangla' => BanglaDate::class,
            'school' => $info, 'frameColor' => $info['color'], 'orientation' => $o, 'asked' => $o, 'examTypes' => [], 'examValue' => '', 'keep' => ['year' => $year],
        ]);
    }
}
