@extends('print.layout')

@php $title = __("teaching.report.{$report}"); @endphp

@section('title', $title)
@section('doc', $title)
@section('controls')<span class="note">{{ $dated ? "{$filters['from']} – {$filters['to']}" : $filters['session'] }}</span>@endsection
@section('links')<a class="btn" href="{{ route('teaching.reports', ['report' => $report]) }}">{{ __('teaching.back') }}</a>@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => $title . ' · ' . ($dated ? "{$filters['from']} – {$filters['to']}" : $filters['session'])])
    <table class="tab">
        <thead><tr>@foreach ($headings as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>@foreach (array_keys($headings) as $k)<td>{{ $r[$k] ?? '' }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">{{ __('teaching.report.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($cut)<p class="notes">{{ __('teaching.report.cut') }}</p>@endif
    <div class="signs"><div></div><div>{{ __('teaching.paper.head') }}</div></div>
    <div class="stamp">{{ now()->format('d/m/Y') }}</div>
</section>
@endsection
