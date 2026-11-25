@extends('print.layout')

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $e = $p->enrollment;
    $class = $e?->schoolClass;
@endphp

@section('title', 'Receipt ' . $p->receipt_no)
@section('doc', 'Admission fee receipt')
@section('controls')<span></span>@endsection

@section('links')
    <a class="btn" href="{{ route('sm.admission-payments.index', ['session' => $p->academic_year]) }}">Back</a>
@endsection

@section('css')
    .receipt-no { text-align: right; font-size: 10pt; }
    table.rc { width: 100%; font-size: 10pt; margin-top: 3mm; }
    table.rc td { border: .4pt solid #000; padding: 2mm 3mm; vertical-align: top; }
    table.rc td.k { width: 38mm; background: #ececec; font-weight: 700; }
    .amount { font-size: 15pt; font-weight: 700; }
    .void { position: absolute; top: 55mm; left: 50%; transform: translateX(-50%) rotate(-18deg); border: 2pt solid #b00; color: #b00; font-size: 28pt; font-weight: 700; padding: 2mm 8mm; letter-spacing: .1em; opacity: .75; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => 'Admission Fee Receipt'])

    <p class="receipt-no"><b>Receipt No:</b> {{ $p->receipt_no }} &nbsp;·&nbsp; <b>Date:</b> {{ $p->paid_at->format('d M Y') }}</p>

    @if ($p->voided_at)
        <div class="void">VOID</div>
    @endif

    <table class="rc">
        <tr><td class="k">Received from</td><td>{{ $e?->student_name }} &nbsp;(Student code {{ $e?->student_id }})</td></tr>
        <tr><td class="k">Class / Session</td><td>{{ $class ? $class->short_label : '—' }} &nbsp;·&nbsp; {{ $p->academic_year }}@if ($e?->roll_number) &nbsp;·&nbsp; Roll {{ $e->roll_number }}@endif</td></tr>
        <tr><td class="k">For</td><td>Admission fee</td></tr>
        <tr><td class="k">Amount</td><td><span class="amount">৳ {{ $money($p->amount) }}</span></td></tr>
        <tr><td class="k">Paid by</td><td>{{ $methods[$p->method] ?? $p->method }}@if ($p->reference) &nbsp;·&nbsp; Ref: {{ $p->reference }}@endif</td></tr>
        @if ($p->note)<tr><td class="k">Note</td><td>{{ $p->note }}</td></tr>@endif
        <tr><td class="k">Received by</td><td>{{ $p->collector?->name ?? '—' }}</td></tr>
        @if ($p->voided_at)<tr><td class="k">Voided</td><td>{{ $p->voided_at->format('d M Y, h:i A') }} — {{ $p->void_reason }}</td></tr>@endif
    </table>

    <div class="signs">
        <div>Guardian</div>
        <div>Cashier</div>
    </div>
    <div class="stamp">Printed {{ now()->format('d M Y, h:i A') }}</div>
</section>
@endsection

@section('runleft', $school['name'])
@section('runright', 'Receipt ' . $p->receipt_no)
