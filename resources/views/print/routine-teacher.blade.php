@extends('print.layout')

@php
    $bn = $lang === 'bn';
    $d = fn ($v) => $bn ? strtr((string) $v, '0123456789', '০১২৩৪৫৬৭৮৯') : (string) $v;
    $t = [
        'title' => $bn ? 'শিক্ষকের রুটিন' : 'Teacher Routine',
        'name' => $bn ? 'শিক্ষকের নাম' : 'Teacher',
        'day' => $bn ? 'বার' : 'Day',
        'week' => $bn ? 'সাপ্তাহিক পিরিয়ড' : 'Periods a week',
        'sig' => $bn ? 'শিক্ষক' : 'Teacher',
        'head' => $bn ? 'প্রধান শিক্ষক' : 'Head of Institution',
        'printed' => $bn ? 'মুদ্রণ' : 'Printed',
        'draft' => $bn ? 'খসড়া — প্রকাশিত নয়' : 'DRAFT — not published',
    ];
    $name = $g['teacher']['name'] ?? '';
@endphp

@section('title', $t['title'] . ' — ' . $name)
@section('doc', $t['title'] . ($published ? '' : ' · ' . $t['draft']))

@section('controls')
    <label>{{ $bn ? 'ভাষা' : 'Language' }}
        <select name="lang" onchange="this.form.submit()"><option value="en" @selected(! $bn)>English</option><option value="bn" @selected($bn)>বাংলা</option></select>
    </label>
    <label>{{ $bn ? 'সংস্করণ' : 'Version' }}
        <select name="source" onchange="this.form.submit()"><option value="published" @selected($published)>{{ $bn ? 'প্রকাশিত' : 'Published' }}</option><option value="draft" @selected(! $published)>{{ $bn ? 'খসড়া' : 'Draft' }}</option></select>
    </label>
@endsection

@section('links')
    <a class="btn" href="{{ route('routine.teachers', ['teacher' => $teacherId]) }}">{{ $bn ? 'ফিরে যান' : 'Back' }}</a>
@endsection

@section('css')
    table.rt { width: 100%; table-layout: fixed; font-size: 9pt; margin-top: 2mm; }
    table.rt th, table.rt td { border: .5pt solid #000; padding: 1.4mm 1.2mm; text-align: center; vertical-align: middle; }
    table.rt thead th { background: #ececec; font-weight: 700; }
    table.rt th.dayc { width: 26mm; text-align: left; background: #f6f6f6; font-weight: 700; }
    table.rt td { height: 15mm; }
    table.rt td .sj { display: block; font-weight: 700; font-size: 9.5pt; }
    table.rt td .tc { display: block; font-size: 8pt; margin-top: .5mm; }
    table.info { width: 100%; font-size: 9.5pt; margin: 0 0 2mm; }
    table.info td { border: .4pt solid #000; padding: 1.2mm 2mm; }
    .draftmark { margin: 0 0 2mm; text-align: center; font-weight: 700; letter-spacing: .12em; font-size: 9pt; border: .6pt dashed #000; padding: 1mm; }
    .signs { margin-top: 18mm; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => $t['title']])

    @unless ($published)<div class="draftmark">{{ $t['draft'] }}</div>@endunless

    <table class="info"><tr><td><b>{{ $t['name'] }}:</b> {{ $name }}</td><td><b>{{ $t['week'] }}:</b> {{ $d($g['periods_per_week']) }}</td></tr></table>

    <table class="rt">
        <thead>
            <tr>
                <th class="dayc">{{ $t['day'] }}</th>
                @foreach ($g['columns'] as $c)<th>{{ $d($c['start']) }} – {{ $d($c['end']) }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($g['days'] as $day)
                <tr>
                    <th class="dayc">{{ $bn ? $day['bn'] : $day['en'] }}</th>
                    @foreach ($g['columns'] as $c)
                        @php $cell = $g['cells'][$day['id'] . '|' . $c['key']] ?? []; @endphp
                        <td>@forelse ($cell as $x)<span class="sj">{{ $x['class'] }}</span><span class="tc">{{ $x['subject'] }}</span>@empty — @endforelse</td>
                    @endforeach
                </tr>
            @endforeach
            @if (! count($g['columns']))<tr><td colspan="2">—</td></tr>@endif
        </tbody>
    </table>

    <div class="signs"><div>{{ $t['sig'] }}</div><div>{{ $t['head'] }}</div></div>
    <div class="stamp">{{ $t['printed'] }}: {{ $d(now()->format('d/m/Y')) }}</div>
</section>
@endsection

@section('runleft', $school['name'])
@section('runright', $t['title'] . ' — ' . $name)
