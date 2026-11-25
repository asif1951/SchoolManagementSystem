@extends('print.layout')

@php
    $title = 'Student Statistics — ' . $s['session'];
    $sum = fn ($key) => collect($s['classes'])->sum($key);
@endphp

@section('title', $title)
@section('doc', 'Student statistics')
@section('controls')<span></span>@endsection

@section('links')
    <a class="btn" href="{{ route('sm.statistics.index', $keep) }}">Back to the statistics</a>
@endsection

@section('css')
    .cols { display: flex; gap: 6mm; align-items: flex-start; }
    .cols > div { flex: 1; min-width: 0; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => $title])

    <table class="meta">
        <tr>
            <td><b>All enrolled:</b> {{ number_format($s['total']) }}</td>
            <td><b>Active:</b> {{ number_format($s['active']) }}</td>
            <td><b>New admissions:</b> {{ number_format($s['new']) }}</td>
            <td><b>Continuing:</b> {{ number_format($s['old']) }}</td>
        </tr>
    </table>

    <div class="cols">
        <div>
            <h2 class="sec">Status</h2>
            <table class="tab">
                <tbody>@foreach ($s['by_status'] as $k => $n)<tr><td class="left">{{ $statuses[$k] ?? $k }}</td><td>{{ number_format($n) }}</td></tr>@endforeach</tbody>
            </table>
        </div>
        <div>
            <h2 class="sec">Gender (active)</h2>
            <table class="tab"><tbody>@foreach ($s['gender'] as $k => $n)<tr><td class="left">{{ $k }}</td><td>{{ number_format($n) }}</td></tr>@endforeach</tbody></table>
        </div>
        <div>
            <h2 class="sec">Religion (active)</h2>
            <table class="tab"><tbody>@foreach ($s['religion'] as $k => $n)<tr><td class="left">{{ $k }}</td><td>{{ number_format($n) }}</td></tr>@endforeach</tbody></table>
        </div>
    </div>

    <h2 class="sec">By class and section (active students)</h2>
    <table class="tab">
        <thead><tr><th class="left">Class</th><th>Section</th><th class="left">Group</th><th>Boys</th><th>Girls</th><th>Other</th><th>Total</th></tr></thead>
        <tbody>
            @foreach ($s['classes'] as $c)
                <tr><td class="left">{{ $c['class'] }}</td><td>{{ $c['section'] ?: '—' }}</td><td class="left">{{ $c['group'] ?: '—' }}</td><td>{{ $c['male'] }}</td><td>{{ $c['female'] }}</td><td>{{ $c['other'] }}</td><td>{{ $c['total'] }}</td></tr>
            @endforeach
            <tr class="grand"><td class="left" colspan="3">Total</td><td>{{ $sum('male') }}</td><td>{{ $sum('female') }}</td><td>{{ $sum('other') }}</td><td>{{ $sum('total') }}</td></tr>
        </tbody>
    </table>

    <p class="notes">Students without a Birth Registration Number: {{ number_format($s['brn_missing']) }}. Figures built {{ $s['built_at'] }}.</p>
    <div class="signs"><div>Prepared by</div><div>Head of Institution</div></div>
</section>
@endsection

@section('runleft', $school['name'])
@section('runright', $title)
