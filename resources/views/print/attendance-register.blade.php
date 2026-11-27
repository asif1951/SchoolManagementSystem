@extends('print.layout')

@php
    $title = __('attendance.register_title');
    $short = ['present' => __('attendance.short.present'), 'absent' => __('attendance.short.absent'), 'late' => __('attendance.short.late'), 'leave' => __('attendance.short.leave'), 'partial' => __('attendance.short.partial')];
    $off = ['weekly' => __('attendance.short.weekly'), 'holiday' => __('attendance.short.holiday'), 'vacation' => __('attendance.short.holiday'), 'closure' => __('attendance.short.closure')];
@endphp

@section('title', $title . ' · ' . $class['label'] . ' ' . $class['section'] . ' · ' . $month)
@section('doc', $title)
@section('runleft', $school['name'])
@section('runright', $class['label'] . ' ' . $class['section'] . ' · ' . $date($r['from']) . ' – ' . $date($r['to']))
@section('controls')
    <label>{{ __('attendance.dates_in') }}
        <select name="cal" onchange="this.form.submit()">
            @foreach (['english', 'bangla', 'both'] as $option) <option value="{{ $option }}" @selected($cal === $option)>{{ __("attendance.cal.$option") }}</option> @endforeach
        </select>
    </label>
@endsection

@section('css')
    @include('print._attendance')
    table.reg { font-size: 7pt; table-layout: auto; }
    table.reg th, table.reg td { padding: .5mm .4mm; }
    table.reg td.off { background: #ddd; }
    table.reg td.left { min-width: 30mm; }
@endsection

@section('body')
    @include('print._head', ['title' => $title])

    <table class="meta">
        <tr>
            <td><b>{{ __('attendance.year') }}:</b> {{ $num($class['year']) }}</td>
            <td><b>{{ __('attendance.class') }}:</b> {{ $class['label'] }} {{ $class['section'] ? '(' . $class['section'] . ')' : '' }}</td>
            <td><b>{{ __('attendance.date_range') }}:</b> {{ $date($r['from']) }} – {{ $date($r['to']) }}</td>
            <td><b>{{ __('attendance.working_days') }}:</b> {{ $num($r['working_days']) }}</td>
        </tr>
    </table>

    <table class="tab reg">
        <thead>
            <tr>
                <th>{{ __('attendance.col.roll') }}</th>
                <th class="left">{{ __('attendance.col.name') }}</th>
                @foreach ($r['days'] as $d) <th>{{ $num($d['day']) }}</th> @endforeach
                @foreach (['present', 'absent', 'late', 'leave'] as $s) <th>{{ $short[$s] }}</th> @endforeach
                <th>%</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($r['students'] as $s)
                <tr>
                    <td>{{ $num($s['roll']) }}</td>
                    <td class="left">{{ $s['name'] }}</td>
                    @foreach ($r['days'] as $d)
                        @php $c = ((array) $s['cells'])[$d['date']] ?? null; @endphp
                        <td @class(['off' => ! $d['working']])>{{ $c ? (isset($c['t']) ? $num($c['t']) : $short[$c['s']]) : (! $d['working'] ? ($off[$d['type']] ?? '–') : '') }}</td>
                    @endforeach
                    @foreach (['present', 'absent', 'late', 'leave'] as $k) <td>{{ $num($s['sum'][$k] ?? 0) }}</td> @endforeach
                    <td>{{ $num($s['sum']['percent'] ?? '') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="legend">{{ __('attendance.register_legend') }}</div>
    @php $closed = collect($r['days'])->filter(fn ($d) => ! $d['working'] && $d['type'] !== 'weekly'); @endphp
    @if ($closed->isNotEmpty())
        <div class="legend">@foreach ($closed->groupBy('reason') as $reason => $days) {{ $reason }}: {{ $days->map(fn ($d) => $num($d['day']))->implode(', ') }}; @endforeach</div>
    @endif

    @if ($r['subjects'])
        <h2 class="sec">{{ __('attendance.report.student_subjects') }}</h2>
        <table class="tab reg">
            <thead><tr><th>{{ __('attendance.col.roll') }}</th><th class="left">{{ __('attendance.col.name') }}</th>@foreach ($r['subjects'] as $x) <th>{{ $x['name'] }}</th> @endforeach</tr></thead>
            <tbody>
                @foreach ($r['students'] as $s)
                    <tr><td>{{ $num($s['roll']) }}</td><td class="left">{{ $s['name'] }}</td>
                        @foreach ($r['subjects'] as $x)
                            @php $v = ((array) $s['subjects'])[$x['id']] ?? null; @endphp
                            <td>{{ $v ? $num($v['present'] + $v['late']) . '/' . $num($v['total']) . ' (' . $num($v['percent']) . '%)' : '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="stamp">{{ __('attendance.generated_on') }}: {{ $generated }}</div>
    <div class="signs">
        <div>{{ __('attendance.teacher_signature') }}</div>
        <div>{{ __('attendance.head_signature') }}</div>
    </div>
@endsection
