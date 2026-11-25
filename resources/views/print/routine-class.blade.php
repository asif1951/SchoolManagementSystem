@extends('print.layout')

@php
    $bn = $lang === 'bn';
    // Bangla digits for Bangla prints
    $d = fn ($v) => $bn ? strtr((string) $v, '0123456789', '০১২৩৪৫৬৭৮৯') : (string) $v;
    $t = [
        'title'   => $bn ? 'ক্লাস রুটিন' : 'Class Routine',
        'class'   => $bn ? 'শ্রেণি' : 'Class',
        'section' => $bn ? 'শাখা' : 'Section',
        'shift'   => $bn ? 'শিফট' : 'Shift',
        'medium'  => $bn ? 'মাধ্যম' : 'Medium',
        'group'   => $bn ? 'বিভাগ' : 'Group',
        'session' => $bn ? 'শিক্ষাবর্ষ' : 'Session',
        'teacher' => $bn ? 'শ্রেণি শিক্ষক' : 'Class teacher',
        'day'     => $bn ? 'বার' : 'Day',
        'period'  => $bn ? 'পিরিয়ড' : 'Period',
        'break'   => $bn ? 'বিরতি' : 'Break',
        'subjects'=> $bn ? 'বিষয় ও শিক্ষক' : 'Subjects and teachers',
        'subject' => $bn ? 'বিষয়' : 'Subject',
        'tname'   => $bn ? 'শিক্ষকের নাম' : 'Teacher',
        'ct'      => $bn ? 'শ্রেণি শিক্ষক' : 'Class Teacher',
        'head'    => $bn ? 'প্রধান শিক্ষক' : 'Head of Institution',
        'draft'   => $bn ? 'খসড়া — প্রকাশিত নয়' : 'DRAFT — not published',
        'printed' => $bn ? 'মুদ্রণ' : 'Printed',
    ];
    $days = $g['days'];
    $periods = $g['periods'];
    $cls = $g['class'];
    $rowspan = count($days);
    $sub = fn ($c) => $c ? $c['subject'] : null;
@endphp

@section('title', $t['title'] . ' — ' . $cls['full_label'])
@section('doc', $t['title'] . ($published ? '' : ' · ' . $t['draft']))

@section('controls')
    <label>{{ $bn ? 'ভাষা' : 'Language' }}
        <select name="lang" onchange="this.form.submit()">
            <option value="en" @selected(! $bn)>English</option>
            <option value="bn" @selected($bn)>বাংলা</option>
        </select>
    </label>
    <label>{{ $bn ? 'শিক্ষক' : 'Teachers' }}
        <select name="who" onchange="this.form.submit()">
            <option value="all" @selected($showAll)>{{ $bn ? 'বিষয়ের সব শিক্ষক' : 'All teachers of the subject' }}</option>
            <option value="one" @selected(! $showAll)>{{ $bn ? 'যিনি ক্লাস নেন' : 'Only who takes the period' }}</option>
        </select>
    </label>
    @if ($canManage)
        <label>{{ $bn ? 'সংস্করণ' : 'Version' }}
            <select name="source" onchange="this.form.submit()">
                <option value="published" @selected($published)>{{ $bn ? 'প্রকাশিত' : 'Published' }}</option>
                <option value="draft" @selected(! $published)>{{ $bn ? 'খসড়া' : 'Draft' }}</option>
            </select>
        </label>
    @endif
@endsection

@section('links')
    @if ($canManage)<a class="btn" href="{{ route('routine.editor', $classId) }}">{{ $bn ? 'রুটিন সম্পাদনা' : 'Edit the routine' }}</a>@endif
    <a class="btn" href="{{ route('routine.index') }}">{{ $bn ? 'সব রুটিন' : 'All routines' }}</a>
@endsection

@section('css')
    table.rt { width: 100%; table-layout: fixed; font-size: 9pt; margin-top: 2mm; }
    table.rt th, table.rt td { border: .5pt solid #000; padding: 1.4mm 1.2mm; text-align: center; vertical-align: middle; }
    table.rt thead th { background: #ececec; font-weight: 700; line-height: 1.25; }
    table.rt thead th .tm { display: block; font-weight: 400; font-size: 7.5pt; margin-top: .4mm; }
    table.rt th.dayc { width: 22mm; text-align: left; background: #f6f6f6; font-weight: 700; font-size: 9.5pt; }
    table.rt td { height: 15mm; }
    table.rt td .sj { display: block; font-weight: 700; font-size: 9.5pt; line-height: 1.2; }
    table.rt td .tc { display: block; font-size: 7.8pt; margin-top: .6mm; line-height: 1.2; }
    table.rt td.brk, table.rt th.brk { width: 11mm; background: #f1f1f1; padding: 0; }
    table.rt td.brk span { display: inline-block; writing-mode: vertical-rl; letter-spacing: .25em; font-weight: 700; font-size: 9pt; }
    table.rt td.empty { color: #888; }
    table.info { width: 100%; font-size: 9pt; margin: 0 0 2mm; }
    table.info td { border: .4pt solid #000; padding: 1mm 2mm; width: 16.66%; }
    table.legend { width: 100%; font-size: 8.8pt; margin-top: 5mm; }
    table.legend th, table.legend td { border: .4pt solid #000; padding: .9mm 2mm; text-align: left; }
    table.legend th { background: #ececec; }
    .draftmark { margin: 0 0 2mm; text-align: center; font-weight: 700; letter-spacing: .12em; font-size: 9pt; border: .6pt dashed #000; padding: 1mm; }
    .signs { margin-top: 16mm; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => $t['title'] . ' — ' . $t['session'] . ' ' . $d($sessionLabel)])

    @unless ($published)<div class="draftmark">{{ $t['draft'] }}</div>@endunless

    <table class="info">
        <tr>
            <td><b>{{ $t['class'] }}:</b> {{ $cls['label'] }}</td>
            <td><b>{{ $t['section'] }}:</b> {{ $cls['section'] ?: '—' }}</td>
            <td><b>{{ $t['shift'] }}:</b> {{ $cls['shift'] ?: '—' }}</td>
            <td><b>{{ $t['medium'] }}:</b> {{ $cls['medium'] ?: '—' }}</td>
            <td><b>{{ $t['group'] }}:</b> {{ $cls['group'] ?: '—' }}</td>
            <td><b>{{ $t['teacher'] }}:</b> {{ $cls['class_teacher'] ?: '—' }}</td>
        </tr>
    </table>

    <table class="rt">
        <thead>
            <tr>
                <th class="dayc">{{ $t['day'] }}</th>
                @foreach ($periods as $p)
                    @if ($p['is_break'])
                        <th class="brk"></th>
                    @else
                        <th>{{ $bn ? $t['period'] . ' ' . $d(preg_replace('/\D/', '', $p['name']) ?: $p['name']) : $p['name'] }}<span class="tm">{{ $d($p['start']) }} – {{ $d($p['end']) }}</span></th>
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($days as $i => $day)
                <tr>
                    <th class="dayc">{{ $bn ? $day['bn'] : $day['en'] }}</th>
                    @foreach ($periods as $p)
                        @if ($p['is_break'])
                            @if ($i === 0)<td class="brk" rowspan="{{ $rowspan }}"><span>{{ $t['break'] }}</span></td>@endif
                        @else
                            @php $c = $g['cells'][$day['id'] . '-' . $p['id']] ?? null; @endphp
                            @if ($c)
                                @php $who = $showAll ? $c['all_teachers'] : array_filter([$c['teacher']]); @endphp
                                <td><span class="sj">{{ $c['subject'] }}</span>@if ($who)<span class="tc">{{ implode(' / ', $who) }}</span>@endif</td>
                            @else
                                <td class="empty">—</td>
                            @endif
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (count($g['subjects']))
        <table class="legend">
            <thead><tr><th style="width:8mm">#</th><th>{{ $t['subject'] }}</th><th>{{ $t['tname'] }}</th></tr></thead>
            <tbody>
                @foreach ($g['subjects'] as $n => $s)
                    <tr><td>{{ $d($n + 1) }}</td><td>{{ $s['name'] }}</td><td>{{ $s['teacher'] ?: '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="signs"><div>{{ $t['ct'] }}</div><div>{{ $t['head'] }}</div></div>
    <div class="stamp">{{ $t['printed'] }}: {{ $d(now()->format('d/m/Y')) }}</div>
</section>
@endsection

@section('runleft', $school['name'])
@section('runright', $t['title'] . ' — ' . $cls['full_label'])
