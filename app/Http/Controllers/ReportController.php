<?php

namespace App\Http\Controllers;

use App\Models\Medium;
use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\Student;
use App\Services\ResultReports;
use App\Services\TabulationBuilder;
use Illuminate\Http\Request;

/**
 * The printable result documents (all A4, plain black-and-white, generated from the marks and
 * attendance already in the system):
 *
 *   marksheet      one student, or every student of a class (a page each)
 *   class summary  one class: figures, grade spread, subject analysis, merit list, failures
 *   class-wise     every class of a year side by side
 *
 * Paper direction follows the data: a table that would be cramped on a portrait page
 * (the class-wise result, with a column for every grade) prints landscape; the others print portrait.
 * The toolbar can force either.
 */
class ReportController extends Controller
{
    /** Same rule as the tabulation: the class teacher, the exam controller, the admin. */
    private function authorizeClass(Request $request, SchoolClass|int|null $class): void
    {
        abort_unless($request->user()->canDo('results.compile', $class), 403, 'You may not open the results of this class.');
    }

    public function marksheet(Request $request, Student $student, ResultReports $reports)
    {
        $class = $student->schoolClass ?? abort(404);
        $this->authorizeClass($request, $class);

        $data = $reports->marksheets($class, $this->exam($request), [$student->id]);

        abort_if(! $data['sheets'], 404);

        return view('print.marksheet', $this->frame($request, 0, 99) + [
            'm' => $data, 'classId' => $class->id, 'single' => $student->id,
        ]);
    }

    public function classMarksheets(Request $request, SchoolClass $class, ResultReports $reports)
    {
        $this->authorizeClass($request, $class);

        $ids = collect($request->input('students', []))->map(fn ($v) => (int) $v)->filter()->values()->all();

        return view('print.marksheet', $this->frame($request, 0, 99) + [
            'm' => $reports->marksheets($class, $this->exam($request), $ids ?: null), 'classId' => $class->id, 'single' => null,
        ]);
    }

    public function classSummary(Request $request, SchoolClass $class, ResultReports $reports)
    {
        $this->authorizeClass($request, $class);

        return view('print.class-summary', $this->frame($request, 10, 12) + [
            's' => $reports->classSummary($class, $this->exam($request)), 'classId' => $class->id,
        ]);
    }

    public function classWise(Request $request, ResultReports $reports)
    {
        $user = $request->user();
        $year = (string) ($request->input('academic_year') ?: (SchoolClass::max('academic_year') ?: now()->year));

        $classes = SchoolClass::query()
            ->visibleTo($user, 'results.compile')
            ->where('academic_year', $year)
            ->where('status', 'active')
            ->when($request->filled('shift'), fn ($q) => $q->where('shift_id', (int) $request->input('shift')))
            ->when($request->filled('medium'), fn ($q) => $q->where('medium_id', (int) $request->input('medium')))
            ->when($request->filled('class_name'), fn ($q) => $q->where('class_name', (string) $request->input('class_name')))
            ->get();

        $data = $reports->schoolResult($year, $this->exam($request), $classes);

        // a column for every grade of the scale: wide tables go landscape
        $columns = 11 + count($data['grades']);

        return view('print.school-result', $this->frame($request, $columns, 14) + [
            'r'          => $data,
            'years'      => SchoolClass::query()->visibleTo($user, 'results.compile')->distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'classNames' => SchoolClass::query()->visibleTo($user, 'results.compile')->where('academic_year', $year)->distinct()->pluck('class_name')->sort(SORT_NATURAL)->values(),
            'shifts'     => Shift::active()->ordered()->get(['id', 'name']),
            'mediums'    => Medium::active()->ordered()->get(['id', 'name']),
            'filters'    => ['academic_year' => $year, 'shift' => (string) $request->input('shift', ''), 'medium' => (string) $request->input('medium', ''), 'class_name' => (string) $request->input('class_name', '')],
        ]);
    }

    // ------------------------------------------------------------------

    private function exam(Request $request): string
    {
        $exam = (string) $request->input('exam_type', 'final');

        return array_key_exists($exam, TabulationBuilder::EXAM_TYPES) ? $exam : 'final';
    }

    /**
     * Paper direction and toolbar data shared by every document.
     * $columns is the width of the document's widest table; above $threshold it prints landscape.
     */
    private function frame(Request $request, int $columns, int $threshold): array
    {
        $asked = in_array($request->input('orientation'), ['portrait', 'landscape'], true) ? $request->input('orientation') : 'auto';

        return [
            'asked'       => $asked,
            'orientation' => $asked === 'auto' ? ($columns > $threshold ? 'landscape' : 'portrait') : $asked,
            'examTypes'   => TabulationBuilder::EXAM_TYPES,
            'examValue'   => $this->exam($request),
            'frameColor'  => app(TabulationBuilder::class)->school()['color'],
            'hasLogo'     => (bool) app(TabulationBuilder::class)->school()['logo'],
        ];
    }
}
