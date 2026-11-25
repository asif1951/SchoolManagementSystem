<?php

namespace App\Http\Controllers\StudentModule;

use App\Exceptions\DuplicateStudentException;
use App\Http\Controllers\Controller;
use App\Models\OnlineApplication;
use App\Models\SchoolClass;
use App\Services\OnlineAdmissionService;
use App\Support\Normalize;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Online Admission Report: the applications that came through the public form, with approve / reject. */
class OnlineAdmissionController extends Controller
{
    public function __construct(private OnlineAdmissionService $applications)
    {
    }

    public function index(Request $request)
    {
        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected'], true) ? $request->input('status') : 'pending';
        $term = trim((string) $request->input('q'));
        $session = $request->filled('session') ? (string) $request->input('session') : null;

        $rows = OnlineApplication::query()->with('decider:id,name', 'profile:id,code')
            ->where('status', $status)
            ->when($session, fn ($q) => $q->where('academic_year', $session))
            ->when($request->filled('class_name'), fn ($q) => $q->where('class_name', (string) $request->input('class_name')))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('application_no', 'like', $term . '%')->orWhere('name_en', 'like', $term . '%')->orWhere('guardian_mobile', 'like', '%' . preg_replace('/\D+/', '', $term))))
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn ($a) => [
                'id' => $a->id, 'no' => $a->application_no, 'session' => $a->academic_year, 'class_name' => $a->class_name, 'name' => $a->name_en, 'name_bn' => $a->name_bn,
                'dob' => $a->dob?->toDateString(), 'gender' => $a->gender, 'father' => $a->father_name, 'mother' => $a->mother_name, 'mobile' => $a->guardian_mobile,
                'previous_school' => $a->previous_school, 'brn_given' => filled($a->brn), 'submitted' => $a->submitted_at?->format('d M Y, h:i A'), 'status' => $a->status,
                'remark' => $a->decision_remark, 'decided_by' => $a->decider?->name, 'decided_at' => $a->decided_at?->format('d M Y, h:i A'), 'student_profile_id' => $a->profile_id,
            ]);

        $sessions = OnlineApplication::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->all();

        return Inertia::render('StudentModule/OnlineAdmissions', [
            'applications' => $rows,
            'status'       => $status,
            'counts'       => OnlineApplication::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'sessions'     => $sessions,
            // classes the approver may place a child in: the application's session, listed per class name
            'classes'      => SchoolClass::whereIn('academic_year', $sessions ?: [(string) now()->year])->where('status', 'active')->orderBy('class_name')->orderBy('section')->get()
                ->map(fn ($c) => ['id' => $c->id, 'session' => (string) $c->academic_year, 'class_name' => $c->class_name, 'label' => trim($c->class_name . ($c->section ? ' ' . $c->section : '') . ($c->group_name ? " ({$c->group_name})" : ''))]),
            'filters'      => ['q' => $term, 'session' => $session ?? '', 'class_name' => (string) $request->input('class_name', '')],
            'canOverride'  => $request->user()->canDo('students.override_duplicate'),
            'duplicate'    => session('duplicate'),
            'flash'        => session()->only(['success', 'error']),
        ]);
    }

    public function approve(Request $request, OnlineApplication $application)
    {
        $d = $request->validate([
            'class_id'        => ['required', 'integer', 'exists:classes,id'],
            'roll_number'     => ['nullable', 'string', 'max:50'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $student = $this->applications->approve($application, $request->user(), (int) $d['class_id'], $d['roll_number'] ?? null, $d['override_reason'] ?? null);
        } catch (DuplicateStudentException $e) {
            return back()->withErrors(['duplicate' => $e->getMessage()])->with('duplicate', $e->summary() + ['application_id' => $application->id]);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "{$application->application_no} approved: {$student->student_name} was added (code {$student->student_id}).");
    }

    public function reject(Request $request, OnlineApplication $application)
    {
        $d = $request->validate(['remark' => ['required', 'string', 'max:500']]);

        try {
            $this->applications->reject($application, $request->user(), $d['remark']);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "{$application->application_no} was rejected.");
    }
}
