@extends('print.layout')

@php
    $title = 'Student Report — ' . $f['session']
        . ($f['class_name'] ? ' — ' . $f['class_name'] : '')
        . ($f['section'] ? ' ' . $f['section'] : '');
    $filters = collect([
        'Gender' => $f['gender'], 'Religion' => $f['religion'], 'Status' => $f['status'] ? ($statuses[$f['status']] ?? $f['status']) : null,
        'Admission' => $f['admission'] ? ucfirst($f['admission']) : null,
    ])->filter();
@endphp

@section('title', $title)
@section('doc', 'Student report')
@section('controls')<span></span>@endsection

@section('links')
    <a class="btn" href="{{ route('sm.reports.index', $keep) }}">Back to the report</a>
@endsection

@section('css')
    table.tab td.left, table.tab th.left { text-align: left; }
    table.tab td { word-break: break-word; }
    table.tab th.no, table.tab td.no { width: 8mm; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => $title])

    <table class="meta">
        <tr>
            <td><b>Students:</b> {{ number_format($total) }}{{ $capped ? '+' : '' }}@if ($capped || $total > $rows->count()) (first {{ number_format($rows->count()) }} shown: use Export for all of them)@endif</td>
            <td colspan="2"><b>Filters:</b> {{ $filters->map(fn ($v, $k) => "$k: $v")->implode(' · ') ?: 'none' }}</td>
            <td><b>Printed:</b> {{ now()->format('d M Y') }}</td>
        </tr>
    </table>

    <table class="tab">
        <thead>
            <tr>
                <th class="no">#</th>
                @foreach ($columns as $c)<th class="left">{{ $labels[$c] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $r)
                <tr class="{{ $i % 2 ? 'zebra' : '' }}">
                    <td class="no">{{ $i + 1 }}</td>
                    @foreach ($columns as $c)<td class="left{{ in_array($c, ['code', 'guardian_mobile', 'brn']) ? ' id' : '' }}">{{ $r[$c] ?? '' }}</td>@endforeach
                </tr>
            @endforeach
            @if ($rows->isEmpty())
                <tr><td colspan="{{ count($columns) + 1 }}">No students match.</td></tr>
            @endif
        </tbody>
    </table>

    <div class="signs"><div>Prepared by</div><div>Checked by</div><div>Head of Institution</div></div>
    <div class="stamp">{{ $school['name'] }} · printed {{ now()->format('d M Y, h:i A') }}</div>
</section>
@endsection

@section('runleft', $school['name'])
@section('runright', $title)
