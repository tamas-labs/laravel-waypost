{{--
  Shift report e-mail (spec: Jelentések / Tartalom), after the design's email/shift-report.blade.php. The variables
  are Waypost\Reports\ReportContent::shift()'s.
--}}
@php
  $S = \Waypost\Reports\Palette::STATUS;
  $F = \Waypost\Reports\Palette::FLAG;
  $cell = 'padding:8px 12px; border-top:1px solid #DCE3EE;';
  $head = 'padding:9px 12px; font-size:11px; font-weight:800; letter-spacing:0.04em; text-transform:uppercase; color:#4F5B73;';
  $label = fn (array $r): string => $r['time'] . ($show_route ? ' · ' . $r['route'] : '');
@endphp
@include('waypost::mail.partials.top', [
  'heading' => $site['name'],
  'subheading' => $shift['date_label'] . ' · ' . $shift['from'] . '–' . $shift['to'] . ' · Őr: ' . $shift['guard'],
])

  @if($shift['late_sync_at'] || $shift['closed_by'] !== 'guard')
  <tr><td style="padding:12px 28px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td style="background:#FDF0D5; border-radius:12px; padding:12px 14px; font-size:14px; line-height:21px;">
        @if($shift['late_sync_at'])<b style="color:#8A5300;">Később érkezett:</b> a telefon {{ $shift['late_sync_at'] }}-kor szinkronizált. @endif
        @if($shift['closed_by'] === 'handover')A műszakot {{ $shift['handover_guard'] ?? 'egy másik őr' }} PIN-belépése zárta (átadás).@endif
        @if($shift['closed_by'] === 'auto')A műszak a menetrend vége után automatikusan zárult.@endif
        @if($shift['closed_by'] === null)A műszak nem zárult le: a telefont lecserélték, mielőtt az őr lezárta volna. A vége a telefon utolsó bejegyzése.@endif
      </td>
    </tr></table>
  </td></tr>
  @endif

  <tr><td style="padding:18px 22px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="6" border="0"><tr>
      @foreach([
        ['n' => $totals['completed'],  'l' => 'teljesítve', 'fg' => '#157F4A', 'bg' => '#D7F2E3'],
        ['n' => $totals['incomplete'], 'l' => 'hiányos',    'fg' => '#B4470F', 'bg' => '#FDE6D8'],
        ['n' => $totals['missed'],     'l' => 'elmaradt',   'fg' => '#C0262D', 'bg' => '#FBE0E1'],
        ['n' => $totals['incidents'],  'l' => 'esemény',    'fg' => '#0F1726', 'bg' => '#EEF2F8'],
      ] as $tile)
      <td width="25%" style="background:{{ $tile['bg'] }}; border-radius:12px; padding:12px 14px;">
        <div style="font-size:28px; line-height:32px; font-weight:800; color:{{ $tile['fg'] }};">{{ $tile['n'] }}</div>
        <div style="font-size:13px; font-weight:700;">{{ $tile['l'] }}</div>
      </td>
      @endforeach
    </tr></table>
  </td></tr>
  <tr><td style="padding:8px 28px 0; font-size:14px; color:#4F5B73;">
    Jelölések: <b style="color:#6B46C1;">{{ $totals['manual'] }} nem igazolt</b> · <b style="color:#8A6100;">{{ $totals['suspicious'] }} időgyanús</b>
    @if($totals['pending']) · <b style="color:#8A6100;">{{ $totals['pending'] }} kör nincs lezárva</b>@endif
  </td></tr>

  @if(count($rounds))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Körök</td></tr>
  <tr><td style="padding:0 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE3EE; border-radius:12px; font-size:14px;">
      <tr style="background:#EEF2F8;">
        @foreach(['Kör', 'Állapot', 'Kezdés', 'Idő', 'Pontok'] as $h)
        <td style="{{ $head }}">{{ $h }}</td>
        @endforeach
      </tr>
      @foreach($rounds as $r)
      <tr>
        <td style="{{ $cell }} font-weight:800;">{{ $label($r) }}</td>
        <td style="{{ $cell }}">
          <span style="display:inline-block; padding:3px 10px; border-radius:999px; background:{{ $S[$r['status']]['bg'] }}; color:{{ $S[$r['status']]['fg'] }}; font-size:12px; font-weight:800;">{{ $r['status_text'] }}</span>
        </td>
        <td style="{{ $cell }} color:#4F5B73;">{{ $r['start'] ?? '–' }}</td>
        <td style="{{ $cell }} color:#4F5B73;">{{ $r['duration_min'] !== null ? $r['duration_min'] . ' p' : '–' }}</td>
        <td style="{{ $cell }} font-weight:700;">{{ $r['done'] }}/{{ $r['total'] }}@if($r['note']) · {{ $r['note'] }}@endif</td>
      </tr>
      @endforeach
    </table>
  </td></tr>
  @endif

  @if(count($incidents))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Események</td></tr>
  @foreach($incidents as $i)
  @include('waypost::mail.partials.incident', ['i' => $i])
  @endforeach
  @endif

  @foreach($rounds as $r)
    @if($r['status'] === 'missed' && $r['done'] === 0)
    <tr><td style="padding:26px 28px 2px; font-size:18px; font-weight:800;">{{ $label($r) }}-s kör · <span style="color:{{ $S['missed']['fg'] }};">Elmaradt</span></td></tr>
    <tr><td style="padding:0 28px; font-size:14px; line-height:21px; color:#4F5B73;">Nem történt leolvasás az időkeretben. Mind a {{ $r['total'] }} pont hiányzik.</td></tr>
    @elseif($r['status'] !== 'completed' || $r['flagged'])
    <tr><td style="padding:26px 28px 2px; font-size:18px; font-weight:800;">{{ $label($r) }}-s kör · <span style="color:{{ $S[$r['status']]['fg'] }};">{{ $r['status_text'] }}</span></td></tr>
    <tr><td style="padding:0 28px 8px; font-size:14px; color:#4F5B73;">Tervezett {{ $r['time'] }} · indult {{ $r['start'] ?? '–' }} · {{ $r['duration_min'] ?? '–' }} perc · útvonal v{{ $r['route_version'] }}</td></tr>
    <tr><td style="padding:0 28px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE3EE; border-radius:12px; font-size:14px;">
        <tr style="background:#EEF2F8;">
          @foreach(['Pont', 'Idő', 'Mód', 'Jelölés'] as $h)
          <td style="{{ $head }}">{{ $h }}</td>
          @endforeach
        </tr>
        @foreach($r['points'] as $p)
        @php $f = $p['flag'] !== null ? $F[$p['flag']] : null; @endphp
        <tr style="background:{{ $f['row'] ?? '#FFFFFF' }};">
          <td style="{{ $cell }} font-weight:700;">{{ $p['name'] }}</td>
          <td style="{{ $cell }} color:#4F5B73;">{{ $p['time'] ?? '–' }}</td>
          <td style="{{ $cell }} color:#4F5B73;">{{ $p['method'] ?? '–' }}</td>
          <td style="{{ $cell }} font-size:13px; font-weight:800; color:{{ $f['fg'] ?? '#157F4A' }};">{{ $p['flag_text'] ?? 'rendben' }}</td>
        </tr>
        @endforeach
      </table>
    </td></tr>
    @endif
  @endforeach

  @if(collect($rounds)->contains(fn (array $r): bool => $r['status'] === 'completed' && ! $r['flagged']))
  <tr><td style="padding:26px 28px 6px; font-size:18px; font-weight:800;">Teljesített körök</td></tr>
  @foreach($rounds as $r)
    @if($r['status'] === 'completed' && ! $r['flagged'])
    <tr><td style="padding:0 28px 8px; font-size:13px; line-height:20px; color:#4F5B73;">
      <b style="color:#157F4A;">{{ $label($r) }}</b> · indult {{ $r['start'] ?? '–' }} · {{ $r['duration_min'] ?? '–' }} perc ·
      @foreach($r['points'] as $p){{ $p['name'] }} {{ $p['time'] }}@if(! $loop->last), @endif @endforeach
    </td></tr>
    @endif
  @endforeach
  @endif

  @if(count($outside))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Körön kívüli leolvasások</td></tr>
  <tr><td style="padding:0 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE3EE; border-radius:12px; font-size:14px;">
      @foreach($outside as $p)
      <tr>
        <td style="{{ $loop->first ? 'padding:8px 12px;' : $cell }} font-weight:700;">{{ $p['name'] }}</td>
        <td style="{{ $loop->first ? 'padding:8px 12px;' : $cell }} color:#4F5B73;">{{ $p['time'] }} · {{ $p['method'] }}</td>
        <td style="{{ $loop->first ? 'padding:8px 12px;' : $cell }} font-size:13px; font-weight:800; color:#B4470F;">{{ $p['flag_text'] }}</td>
      </tr>
      @endforeach
    </table>
  </td></tr>
  @endif

@include('waypost::mail.partials.bottom')
