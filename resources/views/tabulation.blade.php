@php
    // number without trailing zeros: 75.00 -> 75, 62.50 -> 62.5
    $fmt = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

    $subjectById = collect($t['subjects'])->keyBy('id');
    $sheets      = $t['sheets'] ?: [[]];
    $multi       = count($sheets) > 1;
    // one table when the subjects fit; otherwise a table per group of subjects, then a summary table
    $parts       = $multi ? array_merge($sheets, [null]) : $sheets;
    $c           = $t['class'];

    $classTitle = $c['name'] . ($c['section'] ? ' — Section ' . $c['section'] : '');
    $withAttMarks = $t['attendance_marks'] > 0;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tabulation Sheet — {{ $classTitle }} — {{ $t['exam']['label'] }} {{ $t['year'] }}</title>
<style>
    :root { --frame: {{ $t['school']['color'] ?? '#1f3a5f' }}; }
    /* A4 portrait. Plain black on white; one light grey for header cells so it photocopies well. */
    @page { size: A4 portrait; margin: 9mm 8mm 13mm 8mm; @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 7pt Arial, sans-serif; } }

    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: "Liberation Sans", Arial, Helvetica, sans-serif; font-size: 8pt; line-height: 1.25; color: #000; background: #dcdcdc; }

    /* ---- screen only ---- */
    .toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 8px 14px; background: #f4f4f4; border-bottom: 1px solid #999; font-size: 13px; }
    .toolbar label { display: flex; gap: 6px; align-items: center; }
    .toolbar select, .toolbar input[type=number] { font: inherit; padding: 3px 6px; border: 1px solid #888; background: #fff; }
    .toolbar button, .toolbar a.btn { font: inherit; padding: 4px 12px; border: 1px solid #333; background: #fff; color: #000; text-decoration: none; cursor: pointer; }
    .toolbar .spacer { flex: 1; }
    .paper { width: 210mm; margin: 14px auto; padding: 11mm 10mm 15mm; background: #fff; box-shadow: 0 0 5px rgba(0,0,0,.35); position: relative; }
    .paper::before { content: ""; position: absolute; inset: 4mm; border: 1.4pt solid var(--frame); pointer-events: none; }
    .paper::after { content: ""; position: absolute; inset: 5.2mm; border: .4pt solid var(--frame); pointer-events: none; }

    /* ---- the sheet ---- */
    .sheet { break-after: page; page-break-after: always; }
    .sheet:last-child { break-after: auto; page-break-after: auto; }

    .head { text-align: center; margin-bottom: 3mm; }
    .head .school { font-size: 13pt; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .head .contact { font-size: 7.5pt; margin-top: .5mm; }
    .head .title { margin-top: 2.5mm; font-size: 10.5pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; border-top: .6pt solid #000; border-bottom: .6pt solid #000; padding: 1mm 0; }

    .head-row { display: flex; align-items: center; gap: 4mm; }
    .head-side { flex: 0 0 26mm; display: flex; align-items: center; }
    .head-side.right { justify-content: flex-end; }
    .head-mid { flex: 1; text-align: center; min-width: 0; }
    .head img.logo { max-height: 22mm; max-width: 26mm; width: auto; height: auto; object-fit: contain; display: block; margin: 0; }
    .head .photo { width: 25mm; height: 30mm; object-fit: cover; border: .5pt solid #000; display: block; }
    .head .photo.empty { display: flex; align-items: center; justify-content: center; font-size: 7pt; color: #555; border: .5pt dashed #666; }
    .head .title { margin-top: 2.5mm; }

    .gradehead { margin: 3mm 0 1mm; font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
    table.grading { font-size: 7.5pt; }
    table.grading th { text-align: left; background: #ececec; width: 24mm; }
    table.grading td { padding: .4mm 1mm; }

    /* the page frame: a double line in the school's chosen colour, repeated on every printed page */
    .frame { display: none; }

    table.meta { width: 100%; border-collapse: collapse; margin-bottom: 3mm; font-size: 8pt; }
    table.meta td { padding: .6mm 1.5mm; border: .4pt solid #000; width: 25%; }
    table.meta td b { font-weight: 700; }

    table.tab { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 7.5pt; }
    table.tab th, table.tab td { border: .4pt solid #000; padding: .5mm .8mm; text-align: center; vertical-align: middle; overflow: hidden; }
    table.tab thead th { background: #ececec; font-weight: 700; }
    table.tab thead { display: table-header-group; }      /* repeats on every printed page */
    table.tab tr { break-inside: avoid; page-break-inside: avoid; }
    table.tab td.name { text-align: left; white-space: nowrap; text-overflow: ellipsis; }
    table.tab td.id { white-space: nowrap; font-size: 7pt; letter-spacing: -.1pt; }
    table.tab td.left, table.tab th.left { text-align: left; }
    table.tab td.fail { font-weight: 700; }
    table.tab td.pos { font-weight: 700; }
    table.tab tr.foot td { background: #f6f6f6; font-weight: 700; }
    table.tab tr.zebra td { background: #fafafa; }

    /* subject names run bottom-to-top so that many subjects fit a portrait page */
    table.tab th.v { height: 30mm; padding: 0; vertical-align: bottom; }
    table.tab th.v span { display: inline-block; writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; max-height: 28mm; padding: 1mm 0; font-size: 7.2pt; overflow: hidden; text-overflow: ellipsis; }
    table.tab th.opt::after { content: "*"; }

    .figures { margin-top: 3mm; display: flex; flex-wrap: wrap; gap: 1mm 6mm; font-size: 8pt; }
    .legend { margin-top: 2mm; font-size: 7pt; line-height: 1.5; }
    .notes { margin-top: 1.5mm; font-size: 7pt; }
    .signs { margin-top: 14mm; display: flex; justify-content: space-between; gap: 8mm; font-size: 8pt; }
    .signs div { flex: 1; border-top: .5pt solid #000; padding-top: 1mm; text-align: center; }
    .stamp { margin-top: 3mm; font-size: 6.8pt; text-align: right; }

    .runfoot { display: none; }

    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .paper { width: auto; margin: 0; padding: 0; box-shadow: none; }
        .paper::before, .paper::after { display: none; }
        .frame { display: block; position: fixed; top: -5mm; left: -4mm; right: -4mm; bottom: -9mm; border: 1.4pt solid var(--frame); pointer-events: none; }
        .frame::after { content: ""; position: absolute; inset: 1.2mm; border: .4pt solid var(--frame); }
        .runfoot { display: flex; justify-content: space-between; position: fixed; left: 0; right: 0; bottom: 0; font-size: 6.8pt; border-top: .4pt solid #000; padding-top: .8mm; }
    }
</style>
</head>
<body>

<form class="toolbar" method="get" action="{{ route('tabulation.show', $classId) }}">
    <strong>Tabulation sheet</strong>
    <label>Examination
        <select name="exam_type" onchange="this.form.submit()">
            @foreach ($examTypes as $value => $label)
                <option value="{{ $value }}" @selected($t['exam']['value'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label>Subjects per sheet
        <input type="number" name="per_sheet" min="3" max="20" value="{{ $perSheet }}" style="width:60px" onchange="this.form.submit()">
    </label>
    <span class="spacer"></span>
    @unless ($t['school']['logo']) <span style="color:#444">No school logo yet: upload it in Website Management → Settings.</span> @endunless
    <a class="btn" href="{{ route('student.results.show', $classId) }}">Back to results</a>
    <button type="button" onclick="window.print()">Print</button>
</form>

<div class="frame"></div>

<div class="paper">
@foreach ($parts as $pi => $ids)
    @php $isSummary = $ids === null; $ids = $ids ?? []; $showSummaryCols = ! $multi || $isSummary; @endphp

    <section class="sheet">
        @include('print._head', ['school' => $t['school'], 'title' => 'Tabulation Sheet — ' . $t['exam']['label'] . ', ' . $t['year']])

        <table class="meta">
            <tr>
                <td><b>Class:</b> {{ $c['name'] }}</td>
                <td><b>Section:</b> {{ $c['section'] ?: '—' }}</td>
                <td><b>Group:</b> {{ $c['group'] ?: '—' }}</td>
                <td><b>Academic year:</b> {{ $t['year'] }}</td>
            </tr>
            <tr>
                <td><b>Shift:</b> {{ $c['shift'] ?: '—' }}</td>
                <td><b>Medium:</b> {{ $c['medium'] ?: '—' }}</td>
                <td><b>Class teacher:</b> {{ $c['teacher'] ?: '—' }}</td>
                <td><b>Working days:</b> {{ $t['working_days'] }}  ·  <b>Students:</b> {{ $t['summary']['students'] }}</td>
            </tr>
        </table>

        @if ($multi)
            <div style="font-size:7.5pt;margin:-1.5mm 0 1.5mm;">Sheet {{ $pi + 1 }} of {{ count($parts) }} — {{ $isSummary ? 'results and attendance' : 'subject marks' }}</div>
        @endif

        <table class="tab">
            <colgroup>
                <col style="width:6mm"><col style="width:8mm"><col style="width:{{ $isSummary || $showSummaryCols ? '31mm' : '38mm' }}"><col style="width:19mm">
                @foreach ($ids as $id) <col> @endforeach
                @if ($showSummaryCols)
                    <col style="width:9mm"><col style="width:8mm"><col style="width:8mm"><col style="width:7mm">
                    <col style="width:8mm"><col style="width:8mm">
                    @if ($withAttMarks) <col style="width:8mm"> @endif
                    <col style="width:11mm">
                @endif
            </colgroup>
            <thead>
                <tr>
                    <th class="left">Sl</th><th>Roll</th><th class="left">Student name</th><th>ID</th>
                    @foreach ($ids as $id)
                        @php $s = $subjectById[$id]; @endphp
                        <th class="v {{ $s['optional'] ? 'opt' : '' }}"><span title="{{ $s['name'] }}">{{ $s['label'] }}</span></th>
                    @endforeach
                    @if ($showSummaryCols)
                        <th class="v"><span>Total{{ $withAttMarks ? ' (incl. attendance)' : '' }}</span></th>
                        <th class="v"><span>GPA</span></th>
                        <th class="v"><span>Grade</span></th>
                        <th class="v"><span>Position</span></th>
                        <th class="v"><span>Days present</span></th>
                        <th class="v"><span>Attendance %</span></th>
                        @if ($withAttMarks) <th class="v"><span>Attendance marks</span></th> @endif
                        <th class="v"><span>Result</span></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($t['rows'] as $i => $r)
                    <tr class="{{ $i % 2 ? 'zebra' : '' }}">
                        <td class="left">{{ $i + 1 }}</td>
                        <td>{{ $r['roll'] }}</td>
                        <td class="name">{{ $r['name'] }}</td>
                        <td class="id">{{ $r['uid'] }}</td>
                        @foreach ($ids as $id)
                            @php $cell = $r['cells'][$id]; @endphp
                            @if ($cell === false)
                                <td>–</td>
                            @else
                                <td class="{{ $cell['fail'] ? 'fail' : '' }}">{{ $fmt($cell['mark']) }}{{ $cell['fail'] ? '*' : '' }}</td>
                            @endif
                        @endforeach
                        @if ($showSummaryCols)
                            <td><b>{{ $fmt($r['total']) }}</b></td>
                            <td>{{ $r['gpa'] === null ? '' : number_format($r['gpa'], 2) }}</td>
                            <td>{{ $r['grade'] }}</td>
                            <td class="pos">{{ $r['rank'] }}</td>
                            <td>{{ $t['working_days'] ? $r['present'] : '' }}</td>
                            <td>{{ $r['att_percent'] === null ? '' : $fmt($r['att_percent']) }}</td>
                            @if ($withAttMarks) <td>{{ $fmt($r['att_marks']) }}</td> @endif
                            <td>{{ $r['status'] === 'Passed' ? 'Passed' : ($r['status'] === 'Failed' ? 'Failed' : 'Incomplete') }}</td>
                        @endif
                    </tr>
                @endforeach

                @if (! $isSummary && $ids)
                    <tr class="foot">
                        <td colspan="4" class="left">Highest mark</td>
                        @foreach ($ids as $id) <td>{{ $fmt($t['highest'][$id] ?? null) }}</td> @endforeach
                    </tr>
                @endif
            </tbody>
        </table>

        @if ($loop->last)
            <div class="figures">
                <span><b>Students:</b> {{ $t['summary']['students'] }}</span>
                <span><b>Appeared in all subjects:</b> {{ $t['summary']['appeared'] }}</span>
                <span><b>Passed:</b> {{ $t['summary']['passed'] }}</span>
                <span><b>Failed:</b> {{ $t['summary']['failed'] }}</span>
                @if ($t['summary']['incomplete']) <span><b>Marks incomplete:</b> {{ $t['summary']['incomplete'] }}</span> @endif
                @if ($t['summary']['pass_rate'] !== null) <span><b>Pass rate:</b> {{ $fmt($t['summary']['pass_rate']) }}%</span> @endif
                @if ($t['summary']['average_gpa'] !== null) <span><b>Average GPA (passed):</b> {{ number_format($t['summary']['average_gpa'], 2) }}</span> @endif
            </div>

            @include('print._grading', ['grading' => $t['grading']])

            <div class="notes">
                * after a mark: below the passing mark in that subject. Subject names marked * are optional subjects.
                – : the student does not take that subject.
                @if ($withAttMarks) Attendance carries {{ $fmt($t['attendance_marks']) }} marks and is included in the total. @else Attendance is reported only; it carries no marks. @endif
                Attendance counts present and late days out of {{ $t['working_days'] }} working days.
            </div>

            <div class="signs">
                <div>Class Teacher</div>
                <div>Exam Controller</div>
                <div>Head of Institution</div>
            </div>
            <div class="stamp">Generated {{ $t['generated_at']->format('d M Y, h:i A') }}</div>
        @endif
    </section>
@endforeach
</div>

<div class="runfoot">
    <span>{{ $t['school']['name'] }}</span>
    <span>Tabulation — {{ $classTitle }}{{ $c['group'] ? ' · ' . $c['group'] : '' }} — {{ $t['exam']['label'] }} {{ $t['year'] }}</span>
</div>

</body>
</html>
