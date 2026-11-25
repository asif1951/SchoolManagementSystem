<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
<style>
    /* A4, portrait or landscape by the data. Plain black on white; one light grey for header cells so it photocopies well. */
    :root { --frame: {{ $frameColor ?? '#1f3a5f' }}; }
    @page { size: A4 {{ $orientation }}; margin: 10mm 9mm 14mm 9mm; }

    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: "Liberation Sans", Arial, Helvetica, sans-serif; font-size: 9pt; line-height: 1.3; color: #000; background: #dcdcdc; }

    /* ---- screen only ---- */
    .toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 8px 14px; background: #f4f4f4; border-bottom: 1px solid #999; font-size: 13px; }
    .toolbar label { display: flex; gap: 6px; align-items: center; }
    .toolbar select, .toolbar input { font: inherit; padding: 3px 6px; border: 1px solid #888; background: #fff; }
    .toolbar button, .toolbar a.btn { font: inherit; padding: 4px 12px; border: 1px solid #333; background: #fff; color: #000; text-decoration: none; cursor: pointer; }
    .toolbar .spacer { flex: 1; }
    .toolbar .note { color: #444; }
    .paper { width: {{ $orientation === 'landscape' ? '297mm' : '210mm' }}; margin: 14px auto; padding: 11mm 11mm 15mm; background: #fff; box-shadow: 0 0 5px rgba(0,0,0,.35); position: relative; }
    .paper::before { content: ""; position: absolute; inset: 4mm; border: 1.4pt solid var(--frame); pointer-events: none; }
    .paper::after { content: ""; position: absolute; inset: 5.2mm; border: .4pt solid var(--frame); pointer-events: none; }
    .paper > .inner { position: relative; }

    /* ---- the page ---- */
    .sheet { break-after: page; page-break-after: always; }
    .sheet:last-child { break-after: auto; page-break-after: auto; }

    .head { text-align: center; margin-bottom: 3.5mm; }
    .head .school { font-size: 14pt; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .head .contact { font-size: 8pt; margin-top: .5mm; }
    .head .title { margin-top: 3mm; font-size: 11pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; border-top: .6pt solid #000; border-bottom: .6pt solid #000; padding: 1.2mm 0; }


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

    table { border-collapse: collapse; }
    table.meta { width: 100%; margin-bottom: 4mm; font-size: 8.5pt; }
    table.meta td { padding: .8mm 1.8mm; border: .4pt solid #000; width: 25%; }

    h2.sec { margin: 4.5mm 0 1.5mm; font-size: 9.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }

    table.tab { width: 100%; font-size: 8.5pt; }
    table.tab th, table.tab td { border: .4pt solid #000; padding: .8mm 1.4mm; text-align: center; vertical-align: middle; }
    table.tab thead th { background: #ececec; font-weight: 700; }
    table.tab thead { display: table-header-group; }       /* headings repeat on every printed page */
    table.tab tr { break-inside: avoid; page-break-inside: avoid; }
    table.tab td.left, table.tab th.left { text-align: left; }
    table.tab td.fail { font-weight: 700; }
    table.tab tr.foot td { background: #f6f6f6; font-weight: 700; }
    table.tab tr.grand td { background: #e4e4e4; font-weight: 700; }
    table.tab tr.zebra td { background: #fafafa; }
    table.tab td.id { white-space: nowrap; font-size: 8pt; }

    .notes { margin-top: 2.5mm; font-size: 7.5pt; line-height: 1.5; }
    .signs { margin-top: 18mm; display: flex; justify-content: space-between; gap: 10mm; font-size: 8.5pt; break-inside: avoid; }
    .signs div { flex: 1; border-top: .5pt solid #000; padding-top: 1mm; text-align: center; }
    .stamp { margin-top: 3mm; font-size: 7pt; text-align: right; }
    .runfoot { display: none; }

    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .paper { width: auto; margin: 0; padding: 0; box-shadow: none; }
        .paper::before, .paper::after { display: none; }
        .frame { display: block; position: fixed; top: -5.5mm; left: -5.5mm; right: -5.5mm; bottom: -9.5mm; border: 1.4pt solid var(--frame); pointer-events: none; }
        .frame::after { content: ""; position: absolute; inset: 1.2mm; border: .4pt solid var(--frame); }
        .runfoot { display: flex; justify-content: space-between; position: fixed; left: 0; right: 0; bottom: 0; font-size: 7pt; border-top: .4pt solid #000; padding-top: .8mm; }
    }

    @yield('css')
</style>
</head>
<body>

<form class="toolbar" method="get">
    <strong>@yield('doc')</strong>

    @hasSection('controls')
        @yield('controls')
    @else
        <label>Examination
            <select name="exam_type" onchange="this.form.submit()">
                @foreach ($examTypes as $value => $label)
                    <option value="{{ $value }}" @selected($examValue === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
    @endif

    <label>Paper
        <select name="orientation" onchange="this.form.submit()">
            <option value="auto" @selected($asked === 'auto')>Automatic ({{ $orientation === 'landscape' ? 'landscape' : 'portrait' }})</option>
            <option value="portrait" @selected($asked === 'portrait')>A4 portrait</option>
            <option value="landscape" @selected($asked === 'landscape')>A4 landscape</option>
        </select>
    </label>

    @foreach ((array) ($keep ?? []) as $name => $value)
        @if (is_array($value))
            @foreach ($value as $item)
                @if (is_scalar($item))<input type="hidden" name="{{ $name }}[]" value="{{ $item }}">@endif
            @endforeach
        @elseif (is_scalar($value))
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endif
    @endforeach

    <span class="spacer"></span>
    @if (isset($hasLogo) && ! $hasLogo) <span class="note">No school logo yet: upload it in Website Management → Settings.</span> @endif
    @yield('links')
    <button type="button" onclick="window.print()">Print</button>
</form>

<div class="frame"></div>

<div class="paper">
    <div class="inner">
        @yield('body')
    </div>
</div>

<div class="runfoot">
    <span>@yield('runleft')</span>
    <span>@yield('runright')</span>
</div>

</body>
</html>
