@extends('print.layout')

@php
    $title = __("attendance.sheet.$type");
@endphp

@section('title', $title . ' · ' . $class['label'] . ' ' . $class['section'] . ' · ' . $day)
@section('doc', $title)
@section('runleft', $school['name'])
@section('runright', $class['label'] . ' ' . $class['section'] . ' · ' . $date($day))
@section('controls')
    <label>{{ __('attendance.dates_in') }}
        <select name="cal" onchange="this.form.submit()">
            @foreach (['english', 'bangla', 'both'] as $option) <option value="{{ $option }}" @selected($cal === $option)>{{ __("attendance.cal.$option") }}</option> @endforeach
        </select>
    </label>
@endsection

@section('css')
    @include('print._attendance')
    table.sheet-tab td { height: 7.2mm; }
    table.sheet-tab th.mark { width: {{ $type === 'fullday' ? '11mm' : '15mm' }}; }
    td.na { background: repeating-linear-gradient(45deg, #fff, #fff 1.2mm, #999 1.2mm, #999 1.5mm); }
    .summary { margin-top: 4mm; font-size: 9pt; display: flex; flex-wrap: wrap; gap: 6mm; break-inside: avoid; }
    .summary span { border-bottom: .5pt solid #000; min-width: 34mm; display: inline-block; padding-bottom: .5mm; }
@endsection

@section('body')
    @include('print._head', ['title' => $title])

    <table class="meta">
        <tr>
            <td><b>{{ __('attendance.year') }}:</b> {{ $num($class['year']) }}</td>
            <td><b>{{ __('attendance.class') }}:</b> {{ $class['label'] }}</td>
            <td><b>{{ __('attendance.section') }}:</b> {{ $class['section'] ?: '—' }}</td>
            <td><b>{{ __('attendance.date') }}:</b> {{ $date($day) }}</td>
        </tr>
        <tr>
            <td><b>{{ __('attendance.group') }}:</b> {{ $class['group'] ?: '—' }}</td>
            <td><b>{{ __('attendance.shift') }}:</b> {{ $class['shift'] ?: '—' }}</td>
            <td colspan="2"><b>{{ __('attendance.class_teacher') }}:</b> {{ $class['teacher'] ?: '—' }}</td>
        </tr>
        @if ($type === 'period' && $period)
            <tr>
                <td><b>{{ __('attendance.col.period') }}:</b> {{ $period['period_name'] }}</td>
                <td><b>{{ __('attendance.time') }}:</b> {{ $num($period['starts_at']) }} – {{ $num($period['ends_at']) }}</td>
                <td><b>{{ __('attendance.col.subject') }}:</b> {{ $period['subject_name'] ?: '—' }}</td>
                <td><b>{{ __('attendance.teacher') }}:</b> {{ $period['teacher_name'] ?: '—' }}</td>
            </tr>
        @endif
    </table>

    @unless ($state['working'])
        <div class="warn">{{ __('attendance.closed_note', ['reason' => $state['reason']]) }}</div>
    @endunless

    @if ($type !== 'daily' && ! $periods)
        <div class="warn">{{ __('attendance.no_periods') }}</div>
    @else
        <table class="tab sheet-tab">
            <thead>
                <tr>
                    <th style="width:12mm">{{ __('attendance.col.roll') }}</th>
                    <th class="left">{{ __('attendance.col.name') }}</th>
                    @if ($type === 'fullday')
                        @foreach ($periods as $i => $p)
                            <th class="mark">{{ __('attendance.p') }}{{ $num($i + 1) }}<br><small>{{ \Illuminate\Support\Str::limit($p['subject_name'], 9, '…') }}</small></th>
                        @endforeach
                    @else
                        @foreach (['present', 'absent', 'late', 'leave'] as $s) <th class="mark">{{ __("attendance.status.$s") }}</th> @endforeach
                    @endif
                    <th style="width:{{ $type === 'fullday' ? '24mm' : '38mm' }}">{{ __('attendance.col.remark') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($students as $s)
                    <tr>
                        <td>{{ $num($s->roll_number) }}</td>
                        <td class="left">{{ $s->student_name }}</td>
                        @if ($type === 'fullday')
                            @foreach ($periods as $p) <td @class(['na' => ! isset($takes[$p['period_id']][$s->id])])></td> @endforeach
                        @else
                            <td></td><td></td><td></td><td></td>
                        @endif
                        <td></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($type === 'fullday')
            <div class="legend">{{ __('attendance.legend') }}</div>
        @endif

        <div class="summary">
            <div><b>{{ __('attendance.total_students') }}:</b> {{ $num($students->count()) }}</div>
            @foreach (['present', 'absent', 'late', 'leave'] as $s) <div>{{ __("attendance.status.$s") }}: <span></span></div> @endforeach
        </div>
    @endif

    <div class="signs">
        <div>{{ __('attendance.teacher_signature') }}</div>
        <div>{{ __('attendance.date') }}</div>
        <div>{{ __('attendance.head_signature') }}</div>
    </div>
@endsection
