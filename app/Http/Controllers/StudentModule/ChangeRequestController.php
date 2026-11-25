<?php

namespace App\Http\Controllers\StudentModule;

use App\Exceptions\DuplicateStudentException;
use App\Http\Controllers\Controller;
use App\Models\StudentChangeRequest;
use App\Services\StudentRegistry;
use App\Support\Normalize;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Change Requests: proposed edits to protected fields (name, birth date, parents, gender, BRN).
 * An approver compares old with new and approves or rejects; a requester sees their own.
 */
class ChangeRequestController extends Controller
{
    public function __construct(private StudentRegistry $registry)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $canApprove = $user->canDo('students.approve_change');

        abort_unless($canApprove || $user->canDo('students.request_change'), 403);

        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected'], true) ? $request->input('status') : 'pending';
        $canSeeBrn = $user->canDo('students.view_full_brn');

        $requests = StudentChangeRequest::query()
            ->with(['profile:id,code,name_en', 'requester:id,name', 'decider:id,name'])
            ->where('status', $status)
            ->when(! $canApprove, fn ($q) => $q->where('requested_by', $user->id))         // a requester sees only their own
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function ($r) use ($canSeeBrn) {
                $mask = fn ($v) => $r->field === 'brn' && ! $canSeeBrn ? Normalize::maskBrn($v) : $v;

                return [
                    'id' => $r->id, 'field' => $r->field, 'old' => $mask($r->old_value), 'new' => $mask($r->new_value),
                    'reason' => $r->reason, 'status' => $r->status, 'by' => $r->requester?->name,
                    'at' => $r->requested_at?->format('d M Y, h:i A'),
                    'decided_by' => $r->decider?->name, 'decided_at' => $r->decided_at?->format('d M Y, h:i A'), 'remark' => $r->decision_remark,
                    'student' => $r->profile ? ['id' => $r->profile->id, 'name' => $r->profile->name_en, 'code' => $r->profile->code] : null,
                ];
            });

        return Inertia::render('StudentModule/ChangeRequests', [
            'requests'  => $requests,
            'status'    => $status,
            'counts'    => StudentChangeRequest::query()->when(! $canApprove, fn ($q) => $q->where('requested_by', $user->id))
                ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'canDecide' => $canApprove,
            'canOverride' => $user->canDo('students.override_duplicate'),
            'duplicate' => session('duplicate'),
            'flash'     => session()->only(['success', 'error']),
        ]);
    }

    public function approve(Request $request, StudentChangeRequest $changeRequest)
    {
        $data = $request->validate(['remark' => ['nullable', 'string', 'max:500'], 'override_reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->registry->approve($changeRequest, $request->user(), $data['remark'] ?? null, $data['override_reason'] ?? null);
        } catch (DuplicateStudentException $e) {
            return back()->withErrors(['duplicate' => $e->getMessage()])->with('duplicate', $e->summary() + ['request_id' => $changeRequest->id]);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', __('student.approved_done'));
    }

    public function reject(Request $request, StudentChangeRequest $changeRequest)
    {
        $data = $request->validate(['remark' => ['required', 'string', 'max:500']], ['remark.required' => __('student.remark_required')]);

        try {
            $this->registry->reject($changeRequest, $request->user(), $data['remark']);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', __('student.rejected_done'));
    }
}
