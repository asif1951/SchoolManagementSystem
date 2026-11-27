@extends('print.layout')

@php
    $title = $report['title'];
    $c = $report['cards'];
@endphp

@section('title', $title . ' · ' . $report['range']['from'] . ' – ' . $report['range']['to'])
@section('doc', $title)
@section('runleft', $school['name'])
@section('runright', $title . ' · ' . $report['range']['text'])
@section('controls')
    <label>{{ __('attendance.dates_in') }}
        <select name="cal" onchange="this.form.submit()">
            @foreach (['english', 'bangla', 'both'] as $option) <option value="{{ $option }}" @selected($cal === $option)>{{ __("attendance.cal.$option") }}</option> @endforeach
        </select>
    </label>
@endsection

@section('css')
    @include('print._attendance')
@endsection

@section('body')
    @include('print._head', ['title' => $title])

    <table class="meta">
        <tr>
            <td><b>{{ __('attendance.year') }}:</b> {{ $num($year) }}</td>
            <td><b>{{ __('attendance.class') }}:</b> {{ $class ? $class['label'] . ($class['section'] ? ' (' . $class['section'] . ')' : '') : ($className ?: __('attendance.all_classes')) }}</td>
            <td><b>{{ __('attendance.date_range') }}:</b> {{ $date($report['range']['from']) }} – {{ $date($report['range']['to']) }}</td>
            <td><b>{{ $subject ? __('attendance.col.subject') : __('attendance.generated_on') }}:</b> {{ $subject ?: $generated }}</td>
        </tr>
    </table>

    <div class="cards">
        @foreach (['working_days', 'students', 'sessions', 'present', 'absent', 'late', 'leave', 'percent'] as $k)
            <div>{{ __("attendance.card.$k") }}<b>{{ $c[$k] === null ? '—' : $num($c[$k]) . ($k === 'percent' ? '%' : '') }}</b></div>
        @endforeach
    </div>

    <table class="tab">
        <thead><tr>@foreach ($report['columns'] as $col) <th @class(['left' => $col['type'] === 'text'])>{{ $col['label'] }}</th> @endforeach</tr></thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $col)
                        @php $v = $row[$col['key']] ?? null; @endphp
                        <td @class(['left' => $col['type'] === 'text'])>@switch($col['type'])
                            @case('date'){{ $date($v) }}@break
                            @case('status'){{ $v ? __("attendance.status.$v") : '' }}@break
                            @case('pct'){{ $v === null ? '—' : $num($v) . '%' }}@break
                            @case('num'){{ $num($v) }}@break
                            @default{{ in_array($col['key'], ['roll', 'time']) ? $num($v) : $v }}
                        @endswitch</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}">{{ __('attendance.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($report['note']) <div class="legend">{{ $report['note'] }}</div> @endif

    <div class="stamp">{{ __('attendance.generated_on') }}: {{ $generated }}</div>
    <div class="signs">
        <div>{{ __('attendance.prepared_by') }}</div>
        <div>{{ __('attendance.teacher_signature') }}</div>
        <div>{{ __('attendance.head_signature') }}</div>
    </div>
@endsection
