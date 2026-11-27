<?php

namespace App\Http\Controllers\Attendance;

use App\Models\Attendance\Leave;
use App\Models\Student;
use App\Services\Attendance\Access;
use App\Services\Attendance\Leaves;
use App\Services\Attendance\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LeaveController extends Controller
{
    /** classes whose leave the user may see: those they record or approve for; null = all */
    private function scope($user): ?array
    {
        $a = Access::classIds($user, 'attendance.leave');
        $b = Access::classIds($user, 'attendance.leave_approve');

        return $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));
    }

    public function index(Request $request)
    {
        $user = $this->need($request, 'attendance.leave', 'attendance.leave_approve');
        $scope = $this->scope($user);
        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected', 'cancelled', 'all'], true) ? $request->input('status') : 'pending';
        $search = trim((string) $request->input('search', ''));
        $studentId = (int) $request->input('student');

        $base = Leave::query()->when($scope !== null, fn ($q) => $q->whereIn('class_id', $scope));
        $counts = (clone $base)->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status');

        $page = $base->with(['student:id,student_name,student_id,roll_number', 'schoolClass', 'appliedBy:id,name', 'decidedBy:id,name'])
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->when(! $studentId && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('student', fn ($s) => $s->where('student_name', 'like', "%{$search}%")->orWhere('student_id', 'like', "%{$search}%")))
            ->orderByDesc('starts_on')->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn (Leave $l) => [
                'id' => $l->id, 'student_id' => $l->student_id, 'student' => $l->student?->student_name, 'code' => $l->student?->student_id, 'roll' => $l->student?->roll_number,
                'class' => $l->schoolClass ? $this->options(collect([$l->schoolClass]))[0]['label'] : '', 'type' => $l->type,
                'from' => $l->starts_on->toDateString(), 'to' => $l->ends_on->toDateString(), 'reason' => $l->reason, 'status' => $l->status, 'remark' => $l->remark,
                'attachment' => $l->attachment_name, 'applied_by' => $l->appliedBy?->name, 'decided_by' => $l->decidedBy?->name, 'decided_at' => $l->decided_at?->toDateTimeString(),
                'can_decide' => Access::can($user, 'attendance.leave_approve', (int) $l->class_id),
            ]);

        return Inertia::render('Attendance/Leave', [
            'leaves' => $page, 'counts' => $counts, 'types' => Settings::get('leave_types'),
            'filters' => ['status' => $status, 'search' => $search, 'student' => $studentId ?: ''],
            'canApply' => $user->canDo('attendance.leave') || $user->canDo('attendance.leave_approve'),
        ]);
    }

    /** students the user may apply for, as they type */
    public function students(Request $request)
    {
        $user = $this->need($request, 'attendance.leave', 'attendance.leave_approve');
        $scope = $this->scope($user);
        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 1) {
            return response()->json([]);
        }

        return response()->json(Student::query()->where('status', 'active')->with('schoolClass')
            ->when($scope !== null, fn ($s) => $s->whereIn('class_id', $scope))
            ->where('academic_year', $this->year($request))
            ->where(fn ($w) => $w->where('student_name', 'like', "%{$q}%")->orWhere('student_id', 'like', "{$q}%")->orWhere('roll_number', $q))
            ->orderBy('class_id')->orderBy('roll_number')->limit(20)->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->student_name, 'code' => $s->student_id, 'roll' => $s->roll_number,
                'class' => $s->schoolClass ? $this->options(collect([$s->schoolClass]))[0]['label'] : '']));
    }

    public function store(Request $request, Leaves $leaves)
    {
        $user = $this->need($request, 'attendance.leave', 'attendance.leave_approve');
        $d = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'type' => ['required', 'string', 'max:60'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:' . date('Y-m-d', strtotime($request->input('starts_on', 'today') . ' +1 year'))],
            'reason' => ['required', 'string', 'max:500'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ]);

        $leaves->apply($user, Student::findOrFail($d['student_id']), $d, $request->file('attachment'));

        return back()->with('success', __('attendance.msg.leave_applied'));
    }

    public function decide(Request $request, Leave $leave, Leaves $leaves)
    {
        $user = $this->need($request, 'attendance.leave_approve');
        $d = $request->validate(['status' => ['required', 'in:approved,rejected,cancelled'], 'remark' => ['nullable', 'string', 'max:500']]);
        $leaves->decide($user, $leave, $d['status'], $d['remark'] ?? null);

        return back()->with('success', __("attendance.msg.leave_{$d['status']}"));
    }

    public function attachment(Request $request, Leave $leave)
    {
        $user = $this->need($request, 'attendance.leave', 'attendance.leave_approve');
        $scope = $this->scope($user);
        abort_unless($scope === null || in_array((int) $leave->class_id, $scope, true), 403);
        abort_unless($leave->attachment && Storage::disk('local')->exists($leave->attachment), 404);

        return Storage::disk('local')->download($leave->attachment, $leave->attachment_name);
    }
}
