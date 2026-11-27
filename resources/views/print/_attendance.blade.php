{{-- what every attendance document shares: the Bangla font, the look, and the choice of calendar --}}
@font-face { font-family: "Noto Sans Bengali"; font-weight: 400; src: url("{{ asset('fonts/NotoSansBengali-400.ttf') }}") format("truetype"); }
@font-face { font-family: "Noto Sans Bengali"; font-weight: 700; src: url("{{ asset('fonts/NotoSansBengali-700.ttf') }}") format("truetype"); }
body { font-family: "Liberation Sans", Arial, "Noto Sans Bengali", sans-serif; }
table.tab td.left { overflow-wrap: anywhere; }
table.tab tfoot { display: table-row-group; }
.cards { display: flex; flex-wrap: wrap; gap: 2mm; margin: 0 0 3mm; }
.cards div { border: .4pt solid #000; padding: 1mm 3mm; font-size: 8pt; }
.cards b { display: block; font-size: 11pt; }
.legend { margin-top: 2mm; font-size: 8pt; }
.warn { margin: 0 0 3mm; padding: 1.2mm 2mm; border: 1pt solid #000; font-weight: 700; font-size: 8.5pt; }
