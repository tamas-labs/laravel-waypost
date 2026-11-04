{{--
  Weekly and monthly report PDF (dompdf, A4). The variables are Waypost\Reports\ReportContent::period()'s, with the
  photos embedded (data URIs), so the PDF stays evidence after the retention deletes them. DejaVu Sans has every
  Hungarian letter (ő, ű), the PDF core fonts do not.
--}}
@php
  $S = \Waypost\Reports\Palette::STATUS;
  // dompdf lays out a long table in more than linear memory: the long lists go in tables of $chunk rows, with fixed
  // column widths so that they line up as one.
  $chunk = 25;
@endphp
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<title>{{ $title }} – {{ $site['name'] }} – {{ $period_label }}</title>
<style>
  @page { margin: 18mm 14mm 18mm 14mm; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #0F1726; line-height: 1.35; }
  h1 { font-size: 18pt; margin: 0 0 2pt; }
  h2 { font-size: 12pt; margin: 16pt 0 6pt; }
  .muted { color: #4F5B73; }
  .band { background: #0F1420; color: #E8ECF5; padding: 7pt 10pt; font-weight: bold; }
  .band span { float: right; color: #9AA6BF; }
  .warn { background: #FBE0E1; padding: 7pt 10pt; margin-top: 8pt; }
  table { width: 100%; border-collapse: collapse; }
  table.fixed { table-layout: fixed; }
  th { background: #EEF2F8; color: #4F5B73; font-size: 7.5pt; text-transform: uppercase; text-align: left; padding: 4pt 6pt; }
  td { border-top: 0.5pt solid #DCE3EE; padding: 4pt 6pt; vertical-align: top; }
  .tiles td { border: none; padding: 6pt 8pt; width: 20%; }
  .tiles .n { font-size: 16pt; font-weight: bold; }
  .badge { padding: 1pt 5pt; font-size: 8pt; font-weight: bold; }
  .incident { border: 0.5pt solid #DCE3EE; padding: 6pt 8pt; margin-bottom: 6pt; page-break-inside: avoid; }
  /* Floated: dompdf puts a line of several inline images a line too high, over the note. */
  .incident img { float: left; width: 120pt; margin: 4pt 4pt 0 0; }
  tr { page-break-inside: avoid; }
</style>
</head>
<body>
  <div class="band">Waypost <span>{{ $title }}</span></div>
  <h1 style="margin-top: 10pt;">{{ $site['name'] }}</h1>
  <div class="muted">{{ $period_label }} · készült: {{ $generated_at }}</div>

  @if(count($incomplete))
  <div class="warn"><b style="color:#C0262D;">Nem teljes adat:</b> {{ implode('; ', $incomplete) }}. A jelentés a készítéskor ismert adatokból készült.</div>
  @endif

  <table class="tiles" style="margin-top: 10pt;"><tr>
    <td style="background:#EEF2F8;"><div class="n">{{ $totals['expected'] }}</div>várt kör</td>
    <td style="background:#D7F2E3;"><div class="n" style="color:#157F4A;">{{ $totals['completed'] }}</div>teljesítve</td>
    <td style="background:#FDE6D8;"><div class="n" style="color:#B4470F;">{{ $totals['incomplete'] }}</div>hiányos</td>
    <td style="background:#FBE0E1;"><div class="n" style="color:#C0262D;">{{ $totals['missed'] }}</div>elmaradt</td>
    <td style="background:#EEF2F8;"><div class="n">{{ $totals['rate'] }}</div>teljesítési arány</td>
  </tr></table>
  <p class="muted">{{ $totals['incidents'] }} esemény · {{ $totals['manual'] }} nem igazolt · {{ $totals['suspicious'] }} időgyanús leolvasás @if($totals['pending']) · {{ $totals['pending'] }} kör nincs lezárva @endif</p>

  @if(count($days))
  <h2>Napok</h2>
  <table>
    <tr><th>Nap</th><th>Várt</th><th>Teljesítve</th><th>Hiányos</th><th>Elmaradt</th><th>Arány</th></tr>
    @foreach($days as $d)
    <tr>
      <td><b>{{ $d['label'] }}</b> <span class="muted">{{ $d['weekday'] }}</span></td>
      <td>{{ $d['expected'] }}</td>
      <td style="color:#157F4A;">{{ $d['completed'] }}</td>
      <td style="color:#B4470F;">{{ $d['incomplete'] }}</td>
      <td style="color:#C0262D;">{{ $d['missed'] }}</td>
      <td><b>{{ $d['rate'] }}</b></td>
    </tr>
    @endforeach
  </table>
  @endif

  <h2>Elmaradt és hiányos körök</h2>
  @if(count($problem_rounds))
  @foreach(array_chunk($problem_rounds, $chunk) as $rows)
  <table class="fixed">
    @if($loop->first)<tr><th style="width:15%;">Nap</th><th style="width:8%;">Kör</th>@if($show_route)<th style="width:17%;">Útvonal</th>@endif<th style="width:14%;">Állapot</th><th style="width:9%;">Pontok</th><th>Hiányzó pontok</th></tr>@endif
    @foreach($rows as $r)
    <tr>
      <td style="width:15%;">{{ $r['day'] }}</td>
      <td style="width:8%;"><b>{{ $r['time'] }}</b></td>
      @if($show_route)<td style="width:17%;">{{ $r['route'] }}</td>@endif
      <td style="width:14%;"><span class="badge" style="background:{{ $S[$r['status']]['bg'] }}; color:{{ $S[$r['status']]['fg'] }};">{{ $r['status_text'] }}</span></td>
      <td style="width:9%;">{{ $r['done'] }}/{{ $r['total'] }}</td>
      <td class="muted">{{ $r['missing'] !== '' ? $r['missing'] : '–' }}</td>
    </tr>
    @endforeach
  </table>
  @endforeach
  @else
  <p class="muted">Nem volt elmaradt vagy hiányos kör.</p>
  @endif

  @if(count($flags))
  <h2>Jelölések</h2>
  <p>@foreach($flags as $f)<b>{{ $f['count'] }}</b> {{ $f['label'] }}@if(! $loop->last) · @endif @endforeach</p>
  @foreach(array_chunk($flagged_scans, $chunk) as $rows)
  <table class="fixed">
    @if($loop->first)<tr><th style="width:15%;">Nap</th><th style="width:8%;">Idő</th><th style="width:31%;">Pont</th><th style="width:10%;">Mód</th><th>Jelölés</th></tr>@endif
    @foreach($rows as $p)
    <tr><td style="width:15%;">{{ $p['day'] }}</td><td style="width:8%;">{{ $p['time'] }}</td><td style="width:31%;"><b>{{ $p['name'] }}</b></td><td style="width:10%;">{{ $p['method'] }}</td><td style="color:#B4470F;">{{ $p['flag_text'] }}</td></tr>
    @endforeach
  </table>
  @endforeach
  @if($flagged_more)
  <p class="muted">… és további {{ $flagged_more }} jelölt leolvasás (a fenti összesítésben benne vannak).</p>
  @endif
  @endif

  <h2>Események kategóriánként</h2>
  @if(count($incidents))
  <p>@foreach($incident_categories as $c)<b>{{ $c['count'] }}</b> {{ $c['label'] }}@if(! $loop->last) · @endif @endforeach</p>
  @foreach($incidents as $i)
  <div class="incident">
    <b>{{ $i['day'] }} {{ $i['time'] }} · {{ $i['category'] }}</b>@if($i['manual_point'])<span style="color:#6B46C1;"> · nem igazolt pont</span>@endif
    @if($i['place'] !== null || $i['accuracy'] !== null)
    <div class="muted">{{ $i['place'] ?? 'Nincs közeli pont' }}@if($i['accuracy'] !== null) · GPS ±{{ $i['accuracy'] }} m @endif</div>
    @endif
    @if($i['note'])<div>„{{ $i['note'] }}”</div>@endif
    @if($i['photos_missing'])<div style="color:#C0262D;">{{ $i['photos_missing'] }} fotó nem érkezett meg.</div>@endif
    @if(count($i['photos']))<div>@foreach($i['photos'] as $p)<img src="{{ $p['url'] }}" alt="Fotó">@endforeach<div style="clear: both;"></div></div>@endif
  </div>
  @endforeach
  @else
  <p class="muted">Nem volt esemény.</p>
  @endif

  <h2>A napló állapota</h2>
  <p>@if($chain['ok'])<b style="color:#157F4A;">Rendben.</b> A telefon minden bejegyzése ellenőrizve.@else<b style="color:#C0262D;">{{ ucfirst($chain['text']) }}.</b>@if($chain['diagnostics']) A telefon {{ $chain['diagnostics'] }} elutasított bejegyzést jelzett.@endif @endif</p>

  <p class="muted" style="margin-top: 14pt; font-size: 8pt;">A jelölések nem döntések: az eltéréseket a supervisor bírálja el. A fotók a jelentésben az adatmegőrzés után is megmaradnak.</p>
</body>
</html>
