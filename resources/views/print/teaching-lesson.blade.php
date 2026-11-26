@extends('print.layout')

@section('title', $l->title)
@section('doc', __('teaching.nav.lessons'))
@section('controls')<span></span>@endsection
@section('links')<a class="btn" href="{{ route('teaching.lessons') }}">{{ __('teaching.back') }}</a>@endsection

@section('css')
    .block { margin-top: 3mm; break-inside: avoid; }
    .block b { display: block; border-bottom: .4pt solid #000; margin-bottom: 1mm; }
    .block p { margin: 0; white-space: pre-wrap; }
@endsection

@section('body')
<section class="sheet">
    @include('print._head', ['school' => $school, 'title' => __('teaching.lesson.title_one')])
    <table class="meta">
        <tr><td><b>{{ __('teaching.topic') }}:</b> {{ $l->title }}</td><td><b>{{ __('teaching.subject') }}:</b> {{ $l->subject?->name }}</td></tr>
        <tr><td><b>{{ __('teaching.class') }}:</b> {{ $classLabel }}</td><td><b>{{ __('teaching.teacher') }}:</b> {{ $l->owner?->name }}</td></tr>
        <tr><td><b>{{ __('teaching.date') }}:</b> {{ $l->lesson_date?->format('d M Y') }}</td><td><b>{{ __('teaching.lesson.duration') }}:</b> {{ $l->duration }}</td></tr>
    </table>
    @foreach (\App\Models\Teaching\LessonPlan::TEXTS as $f)
        @if ($l->$f)<div class="block"><b>{{ __("teaching.lesson.{$f}") }}</b><p>{{ $l->$f }}</p></div>@endif
    @endforeach
    <div class="signs"><div>{{ __('teaching.teacher') }}</div><div>{{ __('teaching.paper.head') }}</div></div>
</section>
@endsection
