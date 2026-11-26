@extends('print.layout')

@php
    $bnOnly = $mode === 'bangla';
    $withBn = $mode !== 'english';
    $num = fn ($v) => $bnOnly ? $bangla::digits($v) : $v;
    $banglaMonths = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
    $heads = $bnOnly ? ['শনি', 'রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহঃ', 'শুক্র'] : ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    $title = $bnOnly ? 'শিক্ষাপঞ্জি ' . $bangla::digits($year) : 'Academic Calendar ' . $year;
    $name = fn ($e) => $bnOnly ? ($e['title_bn'] ?: $e['title']) : ($mode === 'both' && $e['title_bn'] ? $e['title'] . ' · ' . $e['title_bn'] : $e['title']);
    $range = function ($e) use ($bnOnly, $withBn, $bangla) {
        $en = \Illuminate\Support\Carbon::parse($e['starts_on'])->format('d M') . ($e['ends_on'] !== $e['starts_on'] ? ' – ' . \Illuminate\Support\Carbon::parse($e['ends_on'])->format('d M') : '');
        $bn = $bangla::format($e['starts_on'], false) . ($e['ends_on'] !== $e['starts_on'] ? ' – ' . $bangla::format($e['ends_on'], false) : '');

        return $bnOnly ? $bn : ($withBn ? "{$en} ({$bn})" : $en);
    };
@endphp

@section('title', $title)
@section('doc', $title)
@section('controls')
    <label>Calendar
        <select name="calendar" onchange="this.form.submit()"><option value="english" @selected($mode === 'english')>English</option><option value="bangla" @selected($mode === 'bangla')>বাংলা</option><option value="both" @selected($mode === 'both')>Both</option></select>
    </label>
@endsection
@section('links')<a class="btn" href="{{ route('calendar.index') }}">Back</a>@endsection

@section('css')
    .months { display: grid; grid-template-columns: repeat({{ $orientation === 'landscape' ? 4 : 3 }}, 1fr); gap: 3mm; margin-top: 2mm; }
    .month { break-inside: avoid; }
    .month h3 { margin: 0 0 1mm; font-size: 8.5pt; text-align: center; }
    .month h3 small { display: block; font-weight: 400; font-size: 6.8pt; }
    table.cal { width: 100%; table-layout: fixed; font-size: 7.2pt; }
    table.cal th, table.cal td { border: .3pt solid #999; text-align: center; padding: .3mm 0; line-height: 1.15; }
    table.cal th { background: #ececec; font-size: 6.4pt; }
    table.cal td small { display: block; font-size: 5.6pt; color: #444; }
    table.cal td.off { background: #e0e0e0; font-weight: 700; }
    table.cal td.mark { text-decoration: underline; font-weight: 700; }
    .legend { margin-top: 2mm; font-size: 7pt; }
    h2.list { font-size: 10pt; margin: 4mm 0 1.5mm; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => $title])
    <div class="months">
        @for ($m = 1; $m <= 12; $m++)
            @php
                $first = \Illuminate\Support\Carbon::create($year, $m, 1);
                $blank = ($first->dayOfWeek + 1) % 7;
                $length = $first->daysInMonth;
                $bnSpan = $bangla::MONTHS[$bangla::fromGregorian($first->toDateString())['month'] - 1] . ' – ' . $bangla::MONTHS[$bangla::fromGregorian($first->copy()->endOfMonth()->toDateString())['month'] - 1];
            @endphp
            <div class="month">
                <h3>{{ $bnOnly ? $banglaMonths[$m - 1] : $first->format('F') }}@if ($withBn)<small>{{ $bnSpan }}</small>@endif</h3>
                <table class="cal">
                    <thead><tr>@foreach ($heads as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                        @for ($w = 0; $w < (int) ceil(($blank + $length) / 7); $w++)
                            <tr>
                                @for ($i = 0; $i < 7; $i++)
                                    @php $day = $w * 7 + $i - $blank + 1; @endphp
                                    @if ($day < 1 || $day > $length)
                                        <td></td>
                                    @else
                                        @php $iso = sprintf('%d-%02d-%02d', $year, $m, $day); $b = $bangla::fromGregorian($iso); @endphp
                                        <td class="{{ isset($closed[$iso]) ? 'off' : (isset($byDay[$iso]) ? 'mark' : '') }}">
                                            @if ($bnOnly){{ $bangla::digits($b['day']) }}@else{{ $day }}@if ($withBn)<small>{{ $bangla::digits($b['day']) }}</small>@endif @endif
                                        </td>
                                    @endif
                                @endfor
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        @endfor
    </div>
    <p class="legend">{{ $bnOnly ? 'ধূসর: বিদ্যালয় বন্ধ · নিম্নরেখা: অনুষ্ঠান' : 'Grey: no school · Underlined: an event' }} · {{ $bnOnly ? 'মোট কর্মদিবস' : 'Working days' }}: {{ $num($stats['total']) }}</p>

    <h2 class="list">{{ $bnOnly ? 'তারিখসমূহ' : 'Dates' }}</h2>
    <table class="tab">
        <thead><tr><th class="left">{{ $bnOnly ? 'তারিখ' : 'Date' }}</th><th class="left">{{ $bnOnly ? 'বিষয়' : 'Event' }}</th></tr></thead>
        <tbody>
            @forelse ($events as $e)
                @continue($e['type'] === 'makeup' && $e['audience'] === 'classes')
                <tr><td class="left">{{ $range($e) }}</td><td class="left">{{ $name($e) }}{{ $e['tentative'] ? ($bnOnly ? ' (সম্ভাব্য)' : ' (tentative)') : '' }}</td></tr>
            @empty
                <tr><td colspan="2">—</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($stats['terms'])
        <table class="tab" style="margin-top:3mm">
            <thead><tr><th class="left">{{ $bnOnly ? 'পর্ব' : 'Term' }}</th><th>{{ $bnOnly ? 'কর্মদিবস' : 'Working days' }}</th></tr></thead>
            <tbody>@foreach ($stats['terms'] as $t)<tr><td class="left">{{ $t['title'] }}</td><td>{{ $num($t['working']) }}</td></tr>@endforeach</tbody>
        </table>
    @endif
    <div class="signs"><div></div><div>{{ $bnOnly ? 'প্রতিষ্ঠান প্রধান' : 'Head of Institution' }}</div></div>
</section>
@endsection
