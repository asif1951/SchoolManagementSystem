@extends('print.layout')

@php
    $name = $profile->name_en;
    $son = match (strtolower((string) $profile->gender)) { 'male' => 'son', 'female' => 'daughter', default => 'child' };
    $he = match (strtolower((string) $profile->gender)) { 'male' => 'He', 'female' => 'She', default => 'The student' };
    $his = match (strtolower((string) $profile->gender)) { 'male' => 'his', 'female' => 'her', default => 'the' };
    $class = $enrollment?->schoolClass;
    $classLabel = $class ? trim($class->short_label . ($class->group_name ? ' (' . $class->group_name . ')' : '')) : null;
    $parents = collect([$profile->father_name ? 'Mr. ' . $profile->father_name : null, $profile->mother_name ? 'Mrs. ' . $profile->mother_name : null])->filter()->implode(' and ');
    $dob = $profile->dob?->format('d F Y');
@endphp

@section('title', $c->type_label . ' ' . $c->serial)
@section('doc', $c->type_label . ' · printed ' . $c->print_count . ' time(s)')
@section('controls')<span></span>@endsection

@section('links')
    <a class="btn" href="{{ route('sm.certificates.index') }}">Back to the register</a>
@endsection

@section('css')
    .sheet { position: relative; min-height: 262mm; }
    .sheet > * { position: relative; z-index: 1; }
    .sheet > img.wm { position: absolute; z-index: 0; top: 50%; left: 50%; width: 120mm; height: 120mm; max-width: 70%; object-fit: contain; transform: translate(-50%, -50%); opacity: .07; pointer-events: none; }
    .meta-line { display: flex; justify-content: space-between; margin: 4mm 0 10mm; font-size: 10pt; }
    .cert-title { text-align: center; font-size: 17pt; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; margin: 8mm 0 10mm; text-decoration: underline; text-underline-offset: 2mm; }
    .cert-body { font-size: 12pt; line-height: 2; text-align: justify; margin: 0 6mm; }
    .cert-body b { font-weight: 700; }
    table.facts { width: 100%; font-size: 10.5pt; margin: 9mm 0 0; }
    table.facts td { border: .4pt solid #000; padding: 1.6mm 3mm; }
    table.facts td.k { width: 52mm; background: rgba(236, 236, 236, .85); font-weight: 700; }
    .signs { margin-top: 38mm; }
@endsection

@section('body')
<section class="sheet">
    @if ($school['logo'])
        <img class="wm" src="{{ asset('storage/' . ltrim($school['logo'], '/')) }}" alt="">
    @endif

    @include('print._head', ['school' => $school, 'title' => $c->type_label])

    <div class="meta-line">
        <span><b>Serial No:</b> {{ $c->serial }}</span>
        <span><b>Date of issue:</b> {{ $c->issue_date->format('d F Y') }}</span>
    </div>

    <div class="cert-title">{{ $c->type_label }}</div>

    <p class="cert-body">
        This is to certify that <b>{{ $name }}</b>@if ($parents), {{ $son }} of {{ $parents }}@endif,
        @if ($dob) born on <b>{{ $dob }}</b>, @endif
        bearing student code <b>{{ $profile->code }}</b>,
        @if ($c->type === 'transfer')
            was a student of this institution @if ($classLabel), last studying in <b>{{ $classLabel }}</b>@endif @if ($enrollment) in the session <b>{{ $enrollment->academic_year }}</b>@endif.
            {{ $he }} is leaving the institution @if ($c->remarks) ({{ $c->remarks }})@endif. All dues to the institution were cleared at the time of issue unless otherwise recorded in the register.
            We wish {{ $his === 'the' ? 'the student' : $his }} every success.
        @elseif ($c->type === 'character')
            is a student of this institution @if ($classLabel), studying in <b>{{ $classLabel }}</b>@endif @if ($enrollment) in the session <b>{{ $enrollment->academic_year }}</b>@endif.
            To the best of our knowledge {{ strtolower($he) === 'the student' ? 'the student bears' : strtolower($he) . ' bears' }} a good moral character and has not been involved in any activity against the discipline of the institution.
        @elseif ($c->type === 'testimonial')
            is a student of this institution @if ($classLabel), studying in <b>{{ $classLabel }}</b>@endif @if ($enrollment) in the session <b>{{ $enrollment->academic_year }}</b>@endif.
            {{ $he }} is regular in attendance and satisfactory in conduct. We wish {{ $his === 'the' ? 'the student' : $his }} every success in life.
        @else
            is a student of this institution @if ($classLabel), studying in <b>{{ $classLabel }}</b>@endif @if ($enrollment) in the session <b>{{ $enrollment->academic_year }}</b>@endif.
            @if ($c->remarks) {{ $c->remarks }} @endif
        @endif
    </p>

    <table class="facts">
        <tr><td class="k">Student's name</td><td>{{ $name }}@if ($profile->name_bn) &nbsp;({{ $profile->name_bn }})@endif</td></tr>
        <tr><td class="k">Father's name</td><td>{{ $profile->father_name ?: '—' }}</td></tr>
        <tr><td class="k">Mother's name</td><td>{{ $profile->mother_name ?: '—' }}</td></tr>
        <tr><td class="k">Date of birth</td><td>{{ $dob ?: '—' }}</td></tr>
        @if ($c->type === 'transfer' && $profile->admission_date)<tr><td class="k">Date of admission</td><td>{{ $profile->admission_date->format('d F Y') }}</td></tr>@endif
        @if ($c->remarks && $c->type !== 'other')<tr><td class="k">Remarks</td><td>{{ $c->remarks }}</td></tr>@endif
    </table>

    <div class="signs">
        <div>Prepared by</div>
        <div>Class Teacher</div>
        <div>Head of Institution</div>
    </div>
    <div class="stamp">Serial {{ $c->serial }} · issued by {{ $c->issuer?->name }}</div>
</section>
@endsection

@section('runleft', $school['name'])
@section('runright', $c->type_label . ' ' . $c->serial)
