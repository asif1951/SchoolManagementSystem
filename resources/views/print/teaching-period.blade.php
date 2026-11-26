@extends('print.layout')

@php
    $title = __('teaching.paper.' . $mode);
    $d = fn ($x) => \Illuminate\Support\Carbon::parse($x)->format('d M Y');
@endphp

@section('title', $title)
@section('doc', $title)
@section('controls')<span class="note">{{ $d($from) }} – {{ $d($to) }}</span>@endsection
@section('links')<a class="btn" href="{{ route('teaching.period') }}">{{ __('teaching.back') }}</a>@endsection

@section('css')
    h3.pl { margin: 4mm 0 1.5mm; font-size: 9.5pt; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => $title . ' · ' . $d($from) . ' – ' . $d($to)])

    @forelse ($plans as $p)
        <h3 class="pl">{{ $p['subject'] }} — {{ $p['class'] }} · {{ $p['teacher'] }}</h3>
        <table class="tab">
            <thead><tr><th class="left">{{ __('teaching.chapter') }}</th><th>{{ __('teaching.from') }}</th><th>{{ __('teaching.to') }}</th><th>{{ __('teaching.periods') }}</th><th>{{ __('teaching.expected') }}</th><th>{{ __('teaching.done') }}</th></tr></thead>
            <tbody>
                @foreach ($p['items'] as $i)
                    <tr><td class="left">{{ $i['chapter'] }}</td><td>{{ $d($i['starts_on']) }}</td><td>{{ $d($i['ends_on']) }}</td><td>{{ $i['periods'] }}</td><td>{{ $i['expected'] }}%</td><td>{{ round($i['progress']) }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>{{ __('teaching.period.empty') }}</p>
    @endforelse

    <div class="signs"><div>{{ __('teaching.teacher') }}</div><div>{{ __('teaching.paper.head') }}</div></div>
    <div class="stamp">{{ now()->format('d/m/Y') }}</div>
</section>
@endsection
