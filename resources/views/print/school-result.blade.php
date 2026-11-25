@extends('print.layout')

@php
    $fmt = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $dash = fn ($v) => $v === null ? '—' : $v;
    $cols = 11 + count($r['grades']);

    $scope = collect([
        $filters['class_name'] ?: null,
        $filters['shift'] ? optional($shifts->firstWhere('id', (int) $filters['shift']))->name : null,
        $filters['medium'] ? optional($mediums->firstWhere('id', (int) $filters['medium']))->name . ' medium' : null,
    ])->filter()->implode(' · ');
@endphp

@section('title', 'Class-wise Result — ' . $r['exam']['label'] . ' ' . $r['year'])
@section('doc', 'Class-wise result')

@section('controls')
    <label>Year
        <select name="academic_year" onchange="this.form.submit()">
            @foreach ($years as $y) <option value="{{ $y }}" @selected((string) $y === (string) $filters['academic_year'])>{{ $y }}</option> @endforeach
        </select>
    </label>
    <label>Examination
        <select name="exam_type" onchange="this.form.submit()">
            @foreach ($examTypes as $value => $label) <option value="{{ $value }}" @selected($examValue === $value)>{{ $label }}</option> @endforeach
        </select>
    </label>
    <label>Class
        <select name="class_name" onchange="this.form.submit()">
            <option value="">All classes</option>
            @foreach ($classNames as $n) <option value="{{ $n }}" @selected($filters['class_name'] === $n)>{{ $n }}</option> @endforeach
        </select>
    </label>
    @if ($shifts->count() > 1)
        <label>Shift
            <select name="shift" onchange="this.form.submit()">
                <option value="">All</option>
                @foreach ($shifts as $x) <option value="{{ $x->id }}" @selected($filters['shift'] === (string) $x->id)>{{ $x->name }}</option> @endforeach
            </select>
        </label>
    @endif
    @if ($mediums->count() > 1)
        <label>Medium
            <select name="medium" onchange="this.form.submit()">
                <option value="">All</option>
                @foreach ($mediums as $x) <option value="{{ $x->id }}" @selected($filters['medium'] === (string) $x->id)>{{ $x->name }}</option> @endforeach
            </select>
        </label>
    @endif
@endsection

@section('links')
    <a class="btn" href="{{ route('student.results', ['academic_year' => $filters['academic_year']]) }}">Back to results</a>
@endsection

@section('css')
    table.tab { font-size: 8pt; }
    table.tab td.top { text-align: left; font-size: 7.5pt; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $r['school'], 'title' => 'Class-wise Result — ' . $r['exam']['label'] . ', ' . $r['year']])

    @if ($scope)
        <div style="margin:-1.5mm 0 2.5mm;font-size:8.5pt"><b>Showing:</b> {{ $scope }}</div>
    @endif

    @if ($r['class_count'] === 0)
        <p style="margin-top:8mm;text-align:center">No classes to show for these choices.</p>
    @else
        <table class="tab">
            <thead>
                <tr>
                    <th class="left" rowspan="2">Class</th>
                    <th rowspan="2">Section</th>
                    <th rowspan="2">Group</th>
                    <th rowspan="2">Shift · Medium</th>
                    <th class="left" rowspan="2">Class teacher</th>
                    <th rowspan="2">Students</th>
                    <th rowspan="2">Appeared</th>
                    <th rowspan="2">Passed</th>
                    <th rowspan="2">Failed</th>
                    <th rowspan="2">Pass %</th>
                    <th rowspan="2">Average GPA</th>
                    <th rowspan="2">Highest GPA</th>
                    @if ($r['grades']) <th colspan="{{ count($r['grades']) }}">Students by grade</th> @endif
                    <th class="left" rowspan="2">Topper</th>
                </tr>
                @if ($r['grades'])
                    <tr>@foreach ($r['grades'] as $g) <th>{{ $g }}</th> @endforeach</tr>
                @endif
            </thead>
            <tbody>
                @foreach ($r['blocks'] as $block)
                    @foreach ($block['entries'] as $i => $e)
                        <tr>
                            <td class="left">{{ $i === 0 ? $block['name'] : '' }}</td>
                            <td>{{ $e['section'] ?: '—' }}</td>
                            <td>{{ $e['group'] ?: '—' }}</td>
                            <td>{{ collect([$e['shift'], $e['medium']])->filter()->implode(' · ') ?: '—' }}</td>
                            <td class="left">{{ $e['teacher'] ?: '—' }}</td>
                            <td>{{ $e['students'] }}</td><td>{{ $e['appeared'] }}</td><td>{{ $e['passed'] }}</td><td>{{ $e['failed'] }}</td>
                            <td>{{ $e['pass_rate'] === null ? '—' : $fmt($e['pass_rate']) }}</td>
                            <td>{{ $e['average_gpa'] === null ? '—' : number_format($e['average_gpa'], 2) }}</td>
                            <td>{{ $e['highest_gpa'] === null ? '—' : number_format($e['highest_gpa'], 2) }}</td>
                            @foreach ($r['grades'] as $g) <td>{{ $e['grades'][$g] }}</td> @endforeach
                            <td class="top">{{ $e['topper'] ? $e['topper']['name'] . ' (Roll ' . $e['topper']['roll'] . ', ' . $fmt($e['topper']['total']) . ')' : '—' }}</td>
                        </tr>
                    @endforeach
                    @if ($block['subtotal'])
                        @php $e = $block['subtotal']; @endphp
                        <tr class="foot">
                            <td class="left" colspan="5">{{ $block['name'] }} — all sections</td>
                            <td>{{ $e['students'] }}</td><td>{{ $e['appeared'] }}</td><td>{{ $e['passed'] }}</td><td>{{ $e['failed'] }}</td>
                            <td>{{ $e['pass_rate'] === null ? '—' : $fmt($e['pass_rate']) }}</td>
                            <td>{{ $e['average_gpa'] === null ? '—' : number_format($e['average_gpa'], 2) }}</td>
                            <td>{{ $e['highest_gpa'] === null ? '—' : number_format($e['highest_gpa'], 2) }}</td>
                            @foreach ($r['grades'] as $g) <td>{{ $e['grades'][$g] }}</td> @endforeach
                            <td></td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
            <tfoot>
                @php $e = $r['total']; @endphp
                <tr class="grand">
                    <td class="left" colspan="5">Total — {{ $r['class_count'] }} {{ $r['class_count'] === 1 ? 'class' : 'classes' }}</td>
                    <td>{{ $e['students'] }}</td><td>{{ $e['appeared'] }}</td><td>{{ $e['passed'] }}</td><td>{{ $e['failed'] }}</td>
                    <td>{{ $e['pass_rate'] === null ? '—' : $fmt($e['pass_rate']) }}</td>
                    <td>{{ $e['average_gpa'] === null ? '—' : number_format($e['average_gpa'], 2) }}</td>
                    <td>{{ $e['highest_gpa'] === null ? '—' : number_format($e['highest_gpa'], 2) }}</td>
                    @foreach ($r['grades'] as $g) <td>{{ $e['grades'][$g] }}</td> @endforeach
                    <td></td>
                </tr>
            </tfoot>
        </table>

        @include('print._grading', ['grading' => $r['grading']])

        <div class="notes">
            Appeared = students whose marks are entered for all their subjects; pass rate = passed ÷ appeared. Average GPA is of the students who passed.
            @if ($e['incomplete']) {{ $e['incomplete'] }} student(s) with marks still to be entered are not counted. @endif
            Topper = position 1 in the class.
        </div>
    @endif

    <div class="signs">
        <div>Exam Controller</div>
        <div>Head of Institution</div>
    </div>
    <div class="stamp">Generated {{ $r['generated_at']->format('d M Y, h:i A') }}</div>
</section>
@endsection

@section('runleft', $r['school']['name'])
@section('runright', 'Class-wise result — ' . $r['exam']['label'] . ' ' . $r['year'])
