<?php

namespace App\Http\Controllers\StudentModule;

use App\Exceptions\DuplicateStudentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\QuickStudentRequest;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Services\StudentRegistry;
use App\Support\Normalize;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Student Entry, Quick Entry, the student list and the profile page.
 * Thin on purpose: the rules live in StudentRegistry, the shape of the input in the request classes.
 */
class StudentModuleController extends Controller
{
    public function __construct(private StudentRegistry $registry)
    {
    }

    // ------------------------------------------------------------------
    // list
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $request->user();
        $sessions = $this->sessions();
        $session = (string) $request->input('session', $sessions[0] ?? now()->year);

        $sort = in_array($request->input('sort'), ['name', 'code', 'roll'], true) ? $request->input('sort') : 'roll';

        $query = Student::query()
            ->visibleTo($user, 'students.view')
            ->join('student_profiles', 'student_profiles.id', '=', 'students.profile_id')
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->where('students.academic_year', $session)
            ->select([
                'students.id', 'students.profile_id', 'students.class_id', 'students.roll_number', 'students.status', 'students.academic_year',
                'student_profiles.code', 'student_profiles.name_en', 'student_profiles.gender', 'student_profiles.guardian_mobile',
                'student_profiles.brn',
                // aliased: Student has its own `section` / `class` accessors that would otherwise load the class again for every row
                'classes.class_name as cls_name', 'classes.section as cls_section', 'classes.class_title as cls_title', 'classes.section_title as cls_stitle',
            ]);

        if ($term = trim((string) $request->input('search'))) {
            $digits = preg_replace('/\D+/', '', $term);

            $query->where(function ($q) use ($term, $digits) {
                // indexed lookups only: exact code / mobile, or the start of the name
                $q->where('student_profiles.code', $term)
                  ->orWhere('student_profiles.name_normalized', 'like', Normalize::name($term) . '%');

                if (strlen($digits) >= 7) {
                    $q->orWhere('student_profiles.guardian_mobile_norm', Normalize::mobile($digits));
                }
            });
        }

        $query
            ->when($request->filled('class_id'), fn ($q) => $q->where('students.class_id', (int) $request->input('class_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('students.status', $request->input('status')))
            ->when($request->filled('gender'), fn ($q) => $q->where('student_profiles.gender', $request->input('gender')))
            ->when($request->boolean('brn_missing'), fn ($q) => $q->whereNull('student_profiles.brn'));

        match ($sort) {
            'name' => $query->orderBy('student_profiles.name_normalized'),
            'code' => $query->orderBy('student_profiles.code'),
            default => $query->orderBy('students.class_id')->orderBy('students.roll_no'),   // indexed: read in order, stop after one page
        };

        // A page is read with "limit n+1" (no count needed to know there is a next page); the heading count is capped,
        // so a million students say "10,000+" in milliseconds instead of counting every row.
        $countQuery = (clone $query)->toBase()->cloneWithout(['columns', 'orders'])->select(\Illuminate\Support\Facades\DB::raw('1'))->limit(\App\Services\StudentReports::COUNT_CAP + 1);
        $n = (int) \Illuminate\Support\Facades\DB::query()->fromSub($countQuery, 't')->count();
        $count = $n > \App\Services\StudentReports::COUNT_CAP ? ['n' => \App\Services\StudentReports::COUNT_CAP, 'capped' => true] : ['n' => $n, 'capped' => false];

        $fullBrn = $user->canDo('students.view_full_brn');        // asked once, not for every row
        $paged = $query->simplePaginate(25)->withQueryString();
        $paged->setCollection($paged->getCollection()->map(fn ($s) => [
            'id'              => $s->id,
            'profile_id'      => $s->profile_id,
            'code'            => $s->code,
            'name'            => $s->name_en,
            'gender'          => $s->gender,
            'mobile'          => $s->guardian_mobile,
            'class'           => trim(\App\Models\SchoolClass::joinLabel($s->cls_name, $s->cls_title) . ($s->cls_section || $s->cls_stitle ? ' ' . \App\Models\SchoolClass::joinLabel((string) $s->cls_section, $s->cls_stitle) : '')),
            'roll'            => $s->roll_number,
            'status'          => $s->status,
            'status_label'    => Student::STATUSES[$s->status] ?? $s->status,
            'brn'             => $fullBrn ? $s->brn : Normalize::maskBrn($s->brn),   // never the full number without permission
            'brn_missing'     => blank($s->brn),
        ]));
        $students = \App\Support\Paging::shape($paged, $count);

        return Inertia::render('StudentModule/Index', [
            'students' => $students,
            'sessions' => $sessions,
            'classes'  => $this->classOptions($session),
            'statuses' => Student::STATUSES,
            'filters'  => ['session' => $session, 'search' => $term ?? '', 'class_id' => $request->input('class_id', ''), 'status' => $request->input('status', ''),
                           'gender' => $request->input('gender', ''), 'brn_missing' => $request->boolean('brn_missing'), 'sort' => $sort],
            'can'      => $this->can($request),
            'flash'    => session()->only(['success', 'error']),
        ]);
    }

    // ------------------------------------------------------------------
    // entry
    // ------------------------------------------------------------------

    public function create(Request $request)
    {
        abort_unless($request->user()->canDo('students.create'), 403);

        return Inertia::render('StudentModule/Form', $this->formProps($request) + ['profile' => null, 'mode' => 'create']);
    }

    public function store(StoreStudentRequest $request)
    {
        try {
            $student = $this->registry->admit($request->validated(), $request->user(), 'entry', $request->input('override_reason'));
        } catch (DuplicateStudentException $e) {
            return $this->duplicateResponse($e);
        }

        return redirect()
            ->route('sm.students.show', $student->profile_id)
            ->with('success', __('student.saved_named', ['name' => $student->student_name, 'code' => $student->student_id]));
    }

    public function quick(Request $request)
    {
        abort_unless($request->user()->canDo('students.create'), 403);

        return Inertia::render('StudentModule/Quick', $this->formProps($request));
    }

    public function quickStore(QuickStudentRequest $request)
    {
        try {
            $student = $this->registry->admit($request->validated(), $request->user(), 'quick', $request->input('override_reason'));
        } catch (DuplicateStudentException $e) {
            return $this->duplicateResponse($e);
        }

        $msg = __('student.saved_named', ['name' => $student->student_name, 'code' => $student->student_id]);

        // "Save & add another" stays on the form for the next child
        return $request->boolean('another')
            ? redirect()->route('sm.students.quick', ['class_id' => $student->class_id])->with('success', $msg)
            : redirect()->route('sm.students.show', $student->profile_id)->with('success', $msg);
    }

    // ------------------------------------------------------------------
    // profile, edit, delete
    // ------------------------------------------------------------------

    public function show(Request $request, StudentProfile $profile)
    {
        $user = $request->user();
        $latest = $this->authorizeProfile($user, $profile, 'students.view');

        $canSeeBrn = $user->canDo('students.view_full_brn');

        $histories = $profile->histories()->with('user:id,name')->limit(100)->get()->map(fn ($h) => [
            'id'     => $h->id,
            'field'  => $h->field,
            'old'    => $h->field === 'brn' && ! $canSeeBrn ? Normalize::maskBrn($h->old_value) : $h->old_value,
            'new'    => $h->field === 'brn' && ! $canSeeBrn ? Normalize::maskBrn($h->new_value) : $h->new_value,
            'by'     => $h->user?->name,
            'source' => $h->source,
            'at'     => $h->created_at?->format('d M Y, h:i A'),
        ]);

        $pending = $profile->changeRequests()->pending()->with('requester:id,name')->get()->map(fn ($r) => $this->requestRow($r, $canSeeBrn));

        return Inertia::render('StudentModule/Show', [
            'profile' => $this->profileRow($profile, $user),
            'latest'  => ['status' => $latest->status, 'label' => $latest->status_label],
            'enrollments' => $profile->enrollments()->with('schoolClass:id,class_name,class_title,section,section_title,shift_id,medium_id', 'schoolClass.shift:id,name', 'schoolClass.medium:id,name')
                ->orderByDesc('academic_year')->get()->map(fn ($e) => [
                    'id' => $e->id, 'session' => $e->academic_year, 'class' => trim($e->schoolClass?->short_label . ($e->schoolClass?->shift_name || $e->schoolClass?->medium_name ? ' (' . collect([$e->schoolClass?->shift_name, $e->schoolClass?->medium_name])->filter()->implode(', ') . ')' : '')),
                    'shift' => $e->schoolClass?->shift?->name, 'medium' => $e->schoolClass?->medium?->name, 'roll' => $e->roll_number,
                    'status' => $e->status, 'status_label' => $e->status_label,
                ]),
            'histories' => $histories,
            'pending' => $pending,
            'can' => $this->can($request) + ['edit_this' => $user->canDo('students.edit', $latest->class_id), 'delete_this' => $user->canDo('students.delete', $latest->class_id)],
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    public function edit(Request $request, StudentProfile $profile)
    {
        $this->authorizeProfile($request->user(), $profile, 'students.edit');

        return Inertia::render('StudentModule/Form', $this->formProps($request) + [
            'profile' => $this->profileRow($profile, $request->user(), editing: true),
            'mode'    => 'edit',
        ]);
    }

    public function update(UpdateStudentRequest $request, StudentProfile $profile)
    {
        $this->authorizeProfile($request->user(), $profile, 'students.edit');

        try {
            $result = $this->registry->update(
                $profile, $request->validated(), $request->user(),
                $request->input('change_reason'), $request->input('override_reason')
            );
        } catch (DuplicateStudentException $e) {
            return $this->duplicateResponse($e);
        } catch (AuthorizationException $e) {
            return back()->withErrors(['protected' => $e->getMessage()])->withInput();
        }

        $message = __('student.updated');

        if ($result['requested']) {
            $message .= ' ' . __('student.sent_for_approval', ['count' => count($result['requested'])]);
        }

        return redirect()->route('sm.students.show', $profile->id)->with('success', $message);
    }

    public function destroy(Request $request, StudentProfile $profile)
    {
        $this->authorizeProfile($request->user(), $profile, 'students.delete');

        $blockers = $this->registry->remove($profile, $request->user());

        if ($blockers) {
            $what = collect($blockers)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

            return back()->with('error', __('student.cannot_delete', ['name' => $profile->name_en, 'what' => $what]));
        }

        return redirect()->route('sm.students.index')->with('success', __('student.deleted', ['name' => $profile->name_en]));
    }

    // ------------------------------------------------------------------

    /** The latest enrollment, after checking the user may use $ability on that class (no IDOR through a guessed id). */
    private function authorizeProfile($user, StudentProfile $profile, string $ability): Student
    {
        $latest = $profile->enrollments()->orderByDesc('academic_year')->first();

        abort_unless($latest && $user->canDo($ability, $latest->class_id), 403);

        return $latest;
    }

    private function duplicateResponse(DuplicateStudentException $e)
    {
        return back()
            ->withErrors(['duplicate' => $e->getMessage()])
            ->with('duplicate', $e->summary())
            ->withInput();
    }

    private function profileRow(StudentProfile $p, $user, bool $editing = false): array
    {
        $row = [
            'id' => $p->id, 'code' => $p->code, 'name_en' => $p->name_en, 'name_bn' => $p->name_bn,
            'dob' => $p->dob?->toDateString(), 'gender' => $p->gender, 'religion' => $p->religion, 'blood_group' => $p->blood_group,
            'father_name' => $p->father_name, 'mother_name' => $p->mother_name, 'guardian_name' => $p->guardian_name,
            'guardian_mobile' => $p->guardian_mobile, 'guardian_relation' => $p->guardian_relation,
            'present_address' => $p->present_address, 'permanent_address' => $p->permanent_address,
            'previous_school' => $p->previous_school, 'admission_date' => $p->admission_date?->toDateString(),
            'photo' => $p->photo, 'brn_missing' => $p->brn_missing,
            // the full BRN goes to the browser only for someone allowed to see it (the edit form needs it to change it)
            'brn' => $p->brnFor($user),
        ];

        if ($editing && ! $user->canDo('students.view_full_brn')) {
            $row['brn'] = null;                  // masked values must never be written back: the field is left empty
            $row['brn_masked'] = $p->brnFor($user);
        }

        return $row;
    }

    private function requestRow($r, bool $canSeeBrn): array
    {
        $mask = fn ($v) => $r->field === 'brn' && ! $canSeeBrn ? Normalize::maskBrn($v) : $v;

        return [
            'id' => $r->id, 'field' => $r->field, 'old' => $mask($r->old_value), 'new' => $mask($r->new_value), 'reason' => $r->reason,
            'status' => $r->status, 'by' => $r->requester?->name, 'at' => $r->requested_at?->format('d M Y, h:i A'),
            'student' => $r->relationLoaded('profile') && $r->profile ? ['id' => $r->profile->id, 'name' => $r->profile->name_en, 'code' => $r->profile->code] : null,
            'remark' => $r->decision_remark,
        ];
    }

    private function formProps(Request $request): array
    {
        $sessions = $this->sessions();
        $session = (string) $request->input('session', $sessions[0] ?? now()->year);

        return [
            'sessions' => $sessions,
            'session'  => $session,
            'classes'  => $this->classOptions($session),
            'duplicate' => session('duplicate'),
            'can'      => $this->can($request),
            'preselect' => ['class_id' => $request->input('class_id')],
            'flash'    => session()->only(['success', 'error']),
        ];
    }

    private function can(Request $request): array
    {
        $u = $request->user();

        return [
            'create'             => $u->canDo('students.create'),
            'override_duplicate' => $u->canDo('students.override_duplicate'),
            'approve_change'     => $u->canDo('students.approve_change'),
            'request_change'     => $u->canDo('students.request_change'),
            'view_full_brn'      => $u->canDo('students.view_full_brn'),
            'request_transfer'   => $u->canDo('students.request_transfer'),
            'reinstate'          => $u->canDo('students.reinstate_student'),
            'issue_certificate'  => $u->canDo('students.issue_certificate'),
        ];
    }

    /** session years, newest first (cached; cleared when a class changes) */
    private function sessions(): array
    {
        return Cache::remember('sm.sessions', 300, fn () => SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all()
            ?: [(string) now()->year]);
    }

    /** classes (one per section) of a session for the dropdowns, cached */
    /**
     * The classes a student can be placed in. One entry is one real class: name, section, group, shift and medium.
     * Shift and medium appear in the label only when the school runs more than one of them, so "Class 6 — A" is never
     * ambiguous and never cluttered.
     */
    private function classOptions(string $session): array
    {
        return Cache::remember("sm.classes.{$session}", 300, function () use ($session) {
            $classes = SchoolClass::query()->with(['shift:id,name', 'medium:id,name'])
                ->where('academic_year', $session)->where('status', 'active')
                ->orderBy('class_name')->orderBy('section')
                ->get(['id', 'class_name', 'class_title', 'section', 'section_title', 'study_group_id', 'shift_id', 'medium_id']);

            $manyShifts = $classes->pluck('shift_id')->filter()->unique()->count() > 1;
            $manyMediums = $classes->pluck('medium_id')->filter()->unique()->count() > 1;

            return $classes->map(function ($c) use ($manyShifts, $manyMediums) {
                $extra = collect([$c->group_name, $manyShifts ? $c->shift?->name : null, $manyMediums ? $c->medium?->name : null])->filter()->implode(', ');

                return ['id' => $c->id, 'class_name' => $c->class_name, 'section' => $c->section, 'group' => $c->group_name,
                        'shift' => $c->shift?->name, 'medium' => $c->medium?->name,
                        'label' => trim($c->full_label . ($extra !== '' ? " ({$extra})" : ''))];
            })->all();
        });
    }
}
