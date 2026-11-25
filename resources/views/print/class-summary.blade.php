@extends('print.layout')

@php
    $fmt = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $c = $s['class'];
    $sum = $s['summary'];
    $classTitle = $c['name'] . ($c['section'] ? ' — Section ' . $c['section'] : '');
@endphp

@section('title', 'Result Summary — ' . $classTitle . ' — ' . $s['exam']['label'] . ' ' . $s['year'])
@section('doc', 'Class result summary')

@section('links')
    <a class="btn" href="{{ route('student.results.show', $classId) }}">Back to results</a>
    <a class="btn" href="{{ route('tabulation.show', ['class' => $classId, 'exam_type' => $examValue]) }}">Tabulation sheet</a>
@endsection

@section('css')
    table.glance th, table.glance td { width: auto; }
    table.tab td.left.wrap { white-space: normal; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $s['school'], 'title' => 'Result Summary — ' . $s['exam']['label'] . ', ' . $s['year']])

    <table class="meta">
        <tr>
            <td><b>Class:</b> {{ $c['name'] }}</td>
            <td><b>Section:</b> {{ $c['section'] ?: '—' }}</td>
            <td><b>Group:</b> {{ $c['group'] ?: '—' }}</td>
            <td><b>Academic year:</b> {{ $s['year'] }}</td>
        </tr>
        <tr>
            <td><b>Shift:</b> {{ $c['shift'] ?: '—' }}</td>
            <td><b>Medium:</b> {{ $c['medium'] ?: '—' }}</td>
            <td><b>Class teacher:</b> {{ $c['teacher'] ?: '—' }}</td>
            <td><b>Working days:</b> {{ $s['working_days'] ?: '—' }}</td>
        </tr>
    </table>

    <h2 class="sec">1. Result at a glance</h2>
    <table class="tab glance">
        <thead>
            <tr>
                <th>Students</th><th>Appeared</th><th>Passed</th><th>Failed</th>
                @if ($sum['incomplete']) <th>Incomplete</th> @endif
                <th>Pass rate</th><th>Average GPA</th><th>Highest GPA</th><th>Highest total</th><th>Average attendance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $sum['students'] }}</td><td>{{ $sum['appeared'] }}</td><td>{{ $sum['passed'] }}</td><td>{{ $sum['failed'] }}</td>
                @if ($sum['incomplete']) <td>{{ $sum['incomplete'] }}</td> @endif
                <td>{{ $sum['pass_rate'] === null ? '—' : $fmt($sum['pass_rate']) . '%' }}</td>
                <td>{{ $sum['average_gpa'] === null ? '—' : number_format($sum['average_gpa'], 2) }}</td>
                <td>{{ $sum['highest_gpa'] === null ? '—' : number_format($sum['highest_gpa'], 2) }}</td>
                <td>{{ $fmt($sum['highest_total']) ?: '—' }}</td>
                <td>{{ $sum['attendance'] === null ? '—' : $fmt($sum['attendance']) . '%' }}</td>
            </tr>
        </tbody>
    </table>

    <h2 class="sec">2. Grade distribution</h2>
    <table class="tab">
        <thead>
            <tr><th class="left">Grade</th> @foreach ($s['grades'] as $g) <th>{{ $g['grade'] }}</th> @endforeach <th>Total</th></tr>
        </thead>
        <tbody>
            <tr><td class="left">Students</td> @foreach ($s['grades'] as $g) <td>{{ $g['count'] }}</td> @endforeach <td><b>{{ $sum['appeared'] }}</b></td></tr>
            <tr><td class="left">Percentage</td> @foreach ($s['grades'] as $g) <td>{{ $g['percent'] === null ? '—' : $fmt($g['percent']) . '%' }}</td> @endforeach <td>{{ $sum['appeared'] ? '100%' : '—' }}</td></tr>
        </tbody>
    </table>

    <h2 class="sec">3. Subject-wise result</h2>
    <table class="tab">
        <thead>
            <tr>
                <th>Sl</th><th class="left">Subject</th><th class="left">Subject teacher</th>
                <th>Taking</th><th>Appeared</th><th>Passed</th><th>Failed</th><th>Pass %</th><th>Highest</th><th>Lowest</th><th>Average</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($s['subjects'] as $i => $sub)
                <tr class="{{ $i % 2 ? 'zebra' : '' }}">
                    <td>{{ $i + 1 }}</td>
                    <td class="left">{{ $sub['name'] }}@if ($sub['optional']) <span style="font-size:7.5pt">(optional)</span>@endif</td>
                    <td class="left">{{ $sub['teacher'] ?: '—' }}</td>
                    <td>{{ $sub['taking'] }}</td><td>{{ $sub['appeared'] }}</td><td>{{ $sub['passed'] }}</td><td>{{ $sub['failed'] }}</td>
                    <td>{{ $sub['pass_rate'] === null ? '—' : $fmt($sub['pass_rate']) }}</td>
                    <td>{{ $fmt($sub['highest']) ?: '—' }}</td><td>{{ $fmt($sub['lowest']) ?: '—' }}</td><td>{{ $fmt($sub['average']) ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="sec">4. Merit list</h2>
    @if ($s['merit'])
        <table class="tab">
            <thead><tr><th>Position</th><th>Roll</th><th class="left">Student name</th><th>ID</th><th>Total</th><th>GPA</th><th>Grade</th></tr></thead>
            <tbody>
                @foreach ($s['merit'] as $r)
                    <tr><td><b>{{ $r['rank'] }}</b></td><td>{{ $r['roll'] }}</td><td class="left">{{ $r['name'] }}</td><td class="id">{{ $r['uid'] }}</td><td>{{ $fmt($r['total']) }}</td><td>{{ number_format($r['gpa'], 2) }}</td><td>{{ $r['grade'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="font-size:8.5pt;margin:0">No student has a complete passing result yet.</p>
    @endif

    <h2 class="sec">5. Students who failed</h2>
    @if ($s['failures'])
        <table class="tab">
            <thead><tr><th>Roll</th><th class="left">Student name</th><th>ID</th><th class="left">Failed in</th></tr></thead>
            <tbody>
                @foreach ($s['failures'] as $r)
                    <tr><td>{{ $r['roll'] }}</td><td class="left">{{ $r['name'] }}</td><td class="id">{{ $r['uid'] }}</td><td class="left wrap">{{ $r['subjects'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="font-size:8.5pt;margin:0">No student failed.</p>
    @endif

    @include('print._grading', ['grading' => $s['grading']])

    <div class="notes">
        Appeared = students whose marks are entered for all their subjects. Pass rate = passed ÷ appeared.
        @if ($sum['incomplete']) {{ $sum['incomplete'] }} student(s) with marks still to be entered are not counted in the result. @endif
        Subject figures count the students who take the subject.
    </div>

    <div class="signs">
        <div>Class Teacher</div>
        <div>Exam Controller</div>
        <div>Head of Institution</div>
    </div>
    <div class="stamp">Generated {{ $s['generated_at']->format('d M Y, h:i A') }}</div>
</section>
@endsection

@section('runleft', $s['school']['name'])
@section('runright', 'Result summary — ' . $classTitle . ($c['group'] ? ' · ' . $c['group'] : '') . ' — ' . $s['exam']['label'] . ' ' . $s['year'])
