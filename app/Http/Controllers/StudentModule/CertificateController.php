<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\StudentProfile;
use App\Services\CertificateService;
use App\Services\TabulationBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The certificate register, issuing (everything except Transfer Certificates, which come from a transfer) and printing. */
class CertificateController extends Controller
{
    public function __construct(private CertificateService $certificates)
    {
    }

    public function index(Request $request)
    {
        $type = array_key_exists((string) $request->input('type'), Certificate::TYPES) ? $request->input('type') : null;
        $term = trim((string) $request->input('q'));

        $rows = Certificate::query()->with(['profile:id,code,name_en', 'issuer:id,name'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($request->filled('year'), fn ($q) => $q->whereYear('issue_date', (int) $request->input('year')))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('serial', 'like', $term . '%')->orWhereHas('profile', fn ($p) => $p->where('code', $term)->orWhere('name_normalized', 'like', \App\Support\Normalize::name($term) . '%'))))
            ->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn ($c) => [
                'id' => $c->id, 'serial' => $c->serial, 'type' => $c->type, 'type_label' => $c->type_label, 'student' => $c->profile?->name_en, 'code' => $c->profile?->code, 'profile_id' => $c->profile_id,
                'issue_date' => $c->issue_date?->format('d M Y'), 'by' => $c->issuer?->name, 'remarks' => $c->remarks, 'prints' => $c->print_count, 'void' => $c->voided_at !== null, 'void_reason' => $c->void_reason,
            ]);

        return Inertia::render('StudentModule/Certificates', [
            'certificates' => $rows,
            'types'        => collect(Certificate::TYPES)->except('transfer')->all(),
            'allTypes'     => Certificate::TYPES,
            'filters'      => ['type' => $type ?? '', 'q' => $term, 'year' => (string) $request->input('year', '')],
            'can'          => ['issue' => $request->user()->canDo('students.issue_certificate')],
            'flash'        => session()->only(['success', 'error']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canDo('students.issue_certificate'), 403);

        $d = $request->validate([
            'profile_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'type'       => ['required', 'in:testimonial,character,other'],
            'issue_date' => ['nullable', 'date'],
            'remarks'    => ['nullable', 'string', 'max:500'],
        ]);

        $profile = StudentProfile::findOrFail($d['profile_id']);

        try {
            $c = $this->certificates->issue($d['type'], $profile, $profile->enrollments()->orderByDesc('academic_year')->first(), $request->user(), $d['remarks'] ?? null, $d['issue_date'] ?? null);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "{$c->serial} issued to {$profile->name_en}.");
    }

    public function void(Request $request, Certificate $certificate)
    {
        abort_unless($request->user()->canDo('students.issue_certificate'), 403);

        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->certificates->void($certificate, $request->user(), $d['reason']);

        return back()->with('success', "{$certificate->serial} was voided.");
    }

    /** the printable certificate; every opening counts as a print. It reads the approved student data only. */
    public function print(Request $request, Certificate $certificate, TabulationBuilder $school)
    {
        $certificate->load('profile', 'enrollment.schoolClass', 'issuer:id,name');

        abort_if($certificate->voided_at, 410, 'This certificate was voided and cannot be printed.');

        $this->certificates->printed($certificate, $request->user());

        $profile = $certificate->profile;

        return view('print.certificate', [
            'c' => $certificate->fresh(), 'profile' => $profile, 'enrollment' => $certificate->enrollment, 'school' => $school->school(),
            'frameColor' => $school->school()['color'], 'orientation' => 'portrait', 'asked' => 'portrait', 'examTypes' => [], 'examValue' => '',
        ]);
    }
}
