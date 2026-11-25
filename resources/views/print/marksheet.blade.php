@extends('print.layout')

@php
    // number without trailing zeros: 75.00 -> 75, 62.50 -> 62.5
    $fmt = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $c = $m['class'];
    $classTitle = $c['name'] . ($c['section'] ? ' — ' . $c['section'] : '');
    $withAtt = $m['attendance_marks'] > 0;
    $keep = $single ? [] : [];
@endphp

@section('title', 'Marksheet — ' . $classTitle . ' — ' . $m['exam']['label'] . ' ' . $m['year'])
@section('doc', $single ? 'Marksheet' : 'Marksheets — whole class')

@section('links')
    <a class="btn" href="{{ route('student.results.show', $classId) }}">Back to results</a>
    @if ($single)
        <a class="btn" href="{{ route('reports.marksheet.class', ['class' => $classId, 'exam_type' => $examValue, 'orientation' => $asked]) }}">All students of the class</a>
    @endif
@endsection

@section('css')
    /* the school logo as a faint watermark in the middle of every marksheet page */
    .sheet { position: relative; min-height: {{ $orientation === 'landscape' ? '176mm' : '262mm' }}; }
    .sheet > * { position: relative; z-index: 1; }
    .sheet > img.wm { position: absolute; z-index: 0; top: 50%; left: 50%; width: 105mm; height: 105mm; max-width: 70%; object-fit: contain; transform: translate(-50%, -50%); opacity: .08; pointer-events: none; }
    .sheet table.tab thead th, .sheet table.grading th { background: rgba(236, 236, 236, .85); }
    .sheet table.tab tr.zebra td { background: rgba(250, 250, 250, .6); }
    .sheet table.tab tr.foot td { background: rgba(246, 246, 246, .85); }
    .sheet table.tab tr.grand td { background: rgba(228, 228, 228, .85); }
@endsection

@section('body')
@forelse ($m['sheets'] as $sh)
    @php $st = $sh['student']; @endphp
    <section class="sheet">
        @if ($m['school']['logo'])
            <img class="wm" src="{{ asset('storage/' . ltrim($m['school']['logo'], '/')) }}" alt="">
        @endif
        @include('print._head', ['school' => $m['school'], 'title' => 'Marksheet — ' . $m['exam']['label'] . ', ' . $m['year'], 'withPhoto' => true, 'photo' => $st['photo']])

        <table class="meta">
            <tr>
                <td colspan="2"><b>Student's name:</b> {{ $st['name'] }}</td>
                <td><b>Student ID:</b> {{ $st['uid'] }}</td>
                <td><b>Roll:</b> {{ $st['roll'] ?: '—' }}</td>
            </tr>
            <tr>
                <td colspan="2"><b>Father's name:</b> {{ $st['father'] ?: '—' }}</td>
                <td colspan="2"><b>Mother's name:</b> {{ $st['mother'] ?: '—' }}</td>
            </tr>
            <tr>
                <td><b>Class:</b> {{ $c['name'] }}</td>
                <td><b>Section:</b> {{ $c['section'] ?: '—' }}</td>
                <td><b>Group:</b> {{ $c['group'] ?: '—' }}</td>
                <td><b>Academic year:</b> {{ $m['year'] }}</td>
            </tr>
            <tr>
                <td><b>Shift:</b> {{ $c['shift'] ?: '—' }}</td>
                <td><b>Medium:</b> {{ $c['medium'] ?: '—' }}</td>
                <td><b>Date of birth:</b> {{ $st['dob'] ? \Illuminate\Support\Carbon::parse($st['dob'])->format('d M Y') : '—' }}</td>
                <td><b>Class teacher:</b> {{ $c['teacher'] ?: '—' }}</td>
            </tr>
        </table>

        <table class="tab">
            <colgroup>
                <col style="width:9mm"><col><col style="width:22mm"><col style="width:24mm"><col style="width:22mm"><col style="width:22mm"><col style="width:24mm">
            </colgroup>
            <thead>
                <tr>
                    <th>Sl</th><th class="left">Subject</th><th>Full marks</th><th>Marks obtained</th><th>Letter grade</th><th>Grade point</th><th>Highest in class</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sh['lines'] as $i => $l)
                    <tr class="{{ $i % 2 ? 'zebra' : '' }}">
                        <td>{{ $i + 1 }}</td>
                        <td class="left">{{ $l['name'] }}@if ($l['optional']) <span style="font-size:7.5pt">(optional)</span>@endif</td>
                        <td>{{ $l['full'] }}</td>
                        <td class="{{ $l['fail'] ? 'fail' : '' }}">{{ $l['mark'] === null ? '—' : $fmt($l['mark']) . ($l['fail'] ? '*' : '') }}</td>
                        <td>{{ $l['grade'] ?? '—' }}</td>
                        <td>{{ $l['point'] === null ? '—' : number_format($l['point'], 2) }}</td>
                        <td>{{ $l['highest'] === null ? '' : $fmt($l['highest']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="foot">
                    <td colspan="2" class="left">Total of subjects</td>
                    <td>{{ $sh['full_total'] }}</td>
                    <td>{{ $fmt($sh['subject_total']) }}</td>
                    <td colspan="3"></td>
                </tr>
                @if ($withAtt)
                    <tr class="foot">
                        <td colspan="2" class="left">Attendance marks</td>
                        <td>{{ $fmt($m['attendance_marks']) }}</td>
                        <td>{{ $fmt($sh['att_marks']) }}</td>
                        <td colspan="3"></td>
                    </tr>
                    <tr class="grand">
                        <td colspan="2" class="left">Grand total</td>
                        <td>{{ $sh['full_total'] + $m['attendance_marks'] }}</td>
                        <td>{{ $fmt($sh['total']) }}</td>
                        <td colspan="3"></td>
                    </tr>
                @endif
            </tfoot>
        </table>

        <table class="tab" style="margin-top:3.5mm">
            <thead>
                <tr><th>GPA</th><th>Grade</th><th>Position in class</th><th>Result</th><th>Working days</th><th>Days present</th><th>Attendance</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><b>{{ $sh['gpa'] === null ? '—' : number_format($sh['gpa'], 2) }}</b></td>
                    <td><b>{{ $sh['grade'] ?? '—' }}</b></td>
                    <td>{{ $sh['rank'] === null ? '—' : $sh['rank'] . ' of ' . $sh['ranked'] }}</td>
                    <td><b>{{ $sh['status'] }}</b></td>
                    <td>{{ $m['working_days'] ?: '—' }}</td>
                    <td>{{ $m['working_days'] ? $sh['present'] : '—' }}</td>
                    <td>{{ $sh['att_percent'] === null ? '—' : $fmt($sh['att_percent']) . '%' }}</td>
                </tr>
            </tbody>
        </table>

        @if ($sh['remarks'])
            <div style="margin-top:2.5mm;font-size:9pt"><b>Remarks:</b> {{ $sh['remarks'] }}</div>
        @endif

        @include('print._grading', ['grading' => $m['grading']])

        <div class="notes">
            * Marks below the passing mark. A student who fails any compulsory subject has no GPA.
            @if ($sh['lines'] && collect($sh['lines'])->contains('optional', true)) For an optional subject only the grade points above 2.00 are added to the GPA. @endif
            Position is among students whose result is complete.
        </div>

        <div class="signs">
            <div>Class Teacher</div>
            <div>Guardian</div>
            <div>Head of Institution</div>
        </div>
        <div class="stamp">Issued {{ $m['generated_at']->format('d M Y') }}</div>
    </section>
@empty
    <p>No students to print.</p>
@endforelse
@endsection

@section('runleft', $m['school']['name'])
@section('runright', 'Marksheet — ' . $classTitle . ($c['group'] ? ' · ' . $c['group'] : '') . ' — ' . $m['exam']['label'] . ' ' . $m['year'])
