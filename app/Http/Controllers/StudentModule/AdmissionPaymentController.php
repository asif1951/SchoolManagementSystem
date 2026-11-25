<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Jobs\RunExport;
use App\Models\AdmissionFee;
use App\Models\BackgroundTask;
use App\Models\AdmissionPayment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AdmissionPayments;
use App\Services\TabulationBuilder;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admission Payment Summary: what admission costs, what has been collected, who still owes — by session, class,
 * date and method — with receipts and Excel / CSV export. Needs `students.admission_payments`.
 */
class AdmissionPaymentController extends Controller
{
    public function __construct(private AdmissionPayments $payments)
    {
    }

    public function index(Request $request)
    {
        $years = SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all() ?: [(string) now()->year];
        $session = (string) $request->input('session', $years[0]);
        $class = $request->input('class_name') ?: null;
        $method = array_key_exists((string) $request->input('method'), AdmissionPayment::METHODS) ? $request->input('method') : null;
        [$from, $to] = [$this->date($request->input('from')), $this->date($request->input('to'))];

        $classNames = SchoolClass::where('academic_year', $session)->distinct()->pluck('class_name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        return Inertia::render('StudentModule/AdmissionPayments', [
            'years'      => $years,
            'classNames' => $classNames,
            'filters'    => ['session' => $session, 'class_name' => $class ?? '', 'method' => $method ?? '', 'from' => $from ?? '', 'to' => $to ?? ''],
            'summary'    => $this->payments->summary($session, $class, $from, $to, $method),
            'fees'       => $classNames->map(fn ($n) => ['class_name' => $n, 'amount' => (float) AdmissionFee::where('academic_year', $session)->where('class_name', $n)->value('amount')])->all(),
            'methods'    => AdmissionPayment::METHODS,
            'recent'     => AdmissionPayment::with('enrollment:id,student_name,student_id', 'collector:id,name')->where('academic_year', $session)->latest('id')->limit(15)->get()
                ->map(fn ($p) => ['id' => $p->id, 'receipt' => $p->receipt_no, 'student' => $p->enrollment?->student_name, 'code' => $p->enrollment?->student_id, 'amount' => (float) $p->amount,
                                   'method' => AdmissionPayment::METHODS[$p->method] ?? $p->method, 'by' => $p->collector?->name, 'at' => $p->paid_at?->format('d M Y, h:i A'), 'void' => $p->voided_at !== null]),
            'flash'      => session()->only(['success', 'error']),
        ]);
    }

    public function saveFees(Request $request)
    {
        $d = $request->validate([
            'session' => ['required', 'digits:4'],
            'fees'    => ['required', 'array', 'max:100'],
            'fees.*'  => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $this->payments->setFees($d['session'], $d['fees']);

        return back()->with('success', 'Admission fees saved.');
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'enrollment_id' => ['required', 'integer', 'exists:students,id'],
            'amount'        => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'method'        => ['required', 'in:' . implode(',', array_keys(AdmissionPayment::METHODS))],
            'reference'     => ['nullable', 'string', 'max:100'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $this->payments->record(Student::findOrFail($d['enrollment_id']), (float) $d['amount'], $d['method'], $d['reference'] ?? null, $d['note'] ?? null, $request->user());

        return back()->with('success', "Receipt {$payment->receipt_no} issued.")->with('receipt', $payment->id);
    }

    public function void(Request $request, AdmissionPayment $payment)
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->payments->void($payment, $request->user(), $d['reason']);

        return back()->with('success', "Receipt {$payment->receipt_no} was voided.");
    }

    public function receipt(AdmissionPayment $payment, TabulationBuilder $school)
    {
        $payment->load('enrollment.schoolClass', 'collector:id,name');

        return view('print.receipt', [
            'p' => $payment, 'school' => $school->school(), 'frameColor' => $school->school()['color'], 'orientation' => 'portrait', 'asked' => 'portrait',
            'examTypes' => [], 'examValue' => '', 'methods' => AdmissionPayment::METHODS,
        ]);
    }

    /** the students with dues as Excel (.xlsx) or CSV; cells are formula-safe, large lists are made in the background */
    public function export(Request $request)
    {
        $session = (string) $request->input('session');
        abort_unless(preg_match('/^\d{4}$/', $session) === 1, 422);

        $user = $request->user();
        $format = $request->input('format') === 'csv' ? 'csv' : 'xlsx';
        $payload = ['kind' => 'dues', 'session' => $session, 'class_name' => $request->input('class_name') ?: null, 'to' => $this->date($request->input('to')), 'format' => $format,
                    'name' => 'admission-dues-' . $session . '.' . $format, 'sheet' => 'Dues'];

        $count = $this->payments->summary($session, $payload['class_name'], null, $payload['to'])['with_dues_count'];

        if ($count > StudentReportController::INLINE_ROWS) {
            $task = BackgroundTask::queue('export', $user, $payload, $count);
            RunExport::dispatch($task->id);

            return redirect()->route('sm.exports.show', $task->id);
        }

        $path = tempnam(sys_get_temp_dir(), 'ex');
        RunExport::write(RunExport::rows($payload, $user), $format, $path, 'Dues');

        return response()->download($path, $payload['name'], [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** a student to take a payment from: searches the session's students by code or name */
    public function find(Request $request)
    {
        $q = trim((string) $request->input('q'));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Student::query()->join('classes', 'classes.id', '=', 'students.class_id')
                ->where('students.academic_year', (int) $request->input('session', now()->year))->where('students.status', 'active')
                ->where(fn ($w) => $w->where('students.student_id', $q)->orWhere('students.student_name', 'like', $q . '%'))
                ->orderBy('students.student_name')->limit(10)
                ->get(['students.id', 'students.student_name as name', 'students.student_id as code', 'classes.class_name as cls_name', 'classes.section as cls_section'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'class' => trim($s->cls_name . ' ' . $s->cls_section)])
        );
    }

    private function date(?string $value): ?string
    {
        return \App\Support\Normalize::parseDate($value);
    }
}
