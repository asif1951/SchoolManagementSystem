<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\StudentTransfer;
use App\Services\TransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Transfers: request → dues check → approval → Transfer Certificate. Who may do each step is decided in
 * TransferService; this controller only reads the input and shows the result.
 */
class TransferController extends Controller
{
    public function __construct(private TransferService $transfers)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('students.request_transfer') || $user->canDo('students.approve_transfer'), 403);

        $status = in_array($request->input('status'), ['open', 'issued', 'rejected', 'cancelled'], true) ? $request->input('status') : 'open';

        $query = StudentTransfer::query()->with(['profile:id,code,name_en', 'enrollment:id,class_id,academic_year', 'enrollment.schoolClass:id,class_name,section', 'requester:id,name', 'approver:id,name', 'certificate:id,serial'])
            ->when($status === 'open', fn ($q) => $q->open(), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id');

        return Inertia::render('StudentModule/Transfers', [
            'transfers' => $query->paginate(20)->withQueryString()->through(fn ($t) => $this->row($t)),
            'status'    => $status,
            'counts'    => ['open' => StudentTransfer::open()->count(), 'issued' => StudentTransfer::where('status', 'issued')->count(), 'rejected' => StudentTransfer::where('status', 'rejected')->count(), 'cancelled' => StudentTransfer::where('status', 'cancelled')->count()],
            'can'       => ['approve' => $user->canDo('students.approve_transfer'), 'override' => $user->canDo('students.override_dues'), 'reinstate' => $user->canDo('students.reinstate_student')],
            'flash'     => session()->only(['success', 'error']),
        ]);
    }

    /** start a transfer from a student's profile (their latest enrollment) */
    public function store(Request $request)
    {
        $d = $request->validate([
            'profile_id'  => ['required', 'integer', 'exists:student_profiles,id'],
            'reason'      => ['nullable', 'string', 'max:500'],
            'destination' => ['nullable', 'string', 'max:255'],
        ]);

        $enrollment = StudentProfile::findOrFail($d['profile_id'])->enrollments()->orderByDesc('academic_year')->firstOrFail();

        try {
            $this->transfers->request($enrollment, $request->user(), $d['reason'] ?? null, $d['destination'] ?? null);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('sm.transfers.index')->with('success', 'The transfer was requested. The dues were checked.');
    }

    public function recheck(Request $request, StudentTransfer $transfer)
    {
        $this->transfers->recheck($transfer, $request->user());

        return back()->with('success', 'The dues were checked again.');
    }

    public function approve(Request $request, StudentTransfer $transfer)
    {
        $d = $request->validate(['override_reason' => ['nullable', 'string', 'max:500']]);

        try {
            $done = $this->transfers->approve($transfer, $request->user(), $d['override_reason'] ?? null);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "Transfer Certificate {$done->certificate->serial} issued; the student is now transferred out.");
    }

    public function reject(Request $request, StudentTransfer $transfer)
    {
        $d = $request->validate(['remark' => ['required', 'string', 'max:500']]);

        try {
            $this->transfers->reject($transfer, $request->user(), $d['remark']);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'The transfer was rejected.');
    }

    public function cancel(Request $request, StudentTransfer $transfer)
    {
        $d = $request->validate(['remark' => ['required', 'string', 'max:500']]);

        try {
            $this->transfers->cancel($transfer, $request->user(), $d['remark']);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'The transfer was cancelled.');
    }

    public function reinstate(Request $request, StudentProfile $profile)
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $enrollment = $profile->enrollments()->orderByDesc('academic_year')->firstOrFail();

        try {
            $this->transfers->reinstate($enrollment, $request->user(), $d['reason']);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('sm.students.show', $profile->id)->with('success', "{$profile->name_en} is active again.");
    }

    private function row(StudentTransfer $t): array
    {
        return [
            'id' => $t->id, 'status' => $t->status, 'reason' => $t->reason, 'destination' => $t->destination,
            'student' => ['id' => $t->profile?->id, 'name' => $t->profile?->name_en, 'code' => $t->profile?->code,
                          'class' => ($t->enrollment?->schoolClass?->short_label ?? ''), 'session' => $t->enrollment?->academic_year],
            'dues_total' => (float) $t->dues_total, 'dues' => $t->dues_detail ?? [],
            'trail' => [
                'requested' => ['by' => $t->requester?->name, 'at' => $t->requested_at?->format('d M Y, h:i A')],
                'cleared'   => $t->cleared_at ? ['at' => $t->cleared_at->format('d M Y, h:i A')] : null,
                'approved'  => $t->approved_at ? ['by' => $t->approver?->name, 'at' => $t->approved_at->format('d M Y, h:i A')] : null,
                'issued'    => $t->issued_at ? ['at' => $t->issued_at->format('d M Y, h:i A'), 'serial' => $t->certificate?->serial, 'certificate_id' => $t->certificate_id] : null,
            ],
            'override_reason' => $t->override_reason, 'remark' => $t->decision_remark,
            'reinstated' => $t->reinstated_at ? ['at' => $t->reinstated_at->format('d M Y'), 'reason' => $t->reinstate_reason] : null,
        ];
    }
}
