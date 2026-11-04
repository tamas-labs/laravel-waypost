{{--
  Daily, weekly and monthly report e-mail (spec: Jelentések / Tartalom). The variables are
  Waypost\Reports\ReportContent::period()'s; the weekly and monthly report has its PDF attached.
--}}
@php
  $S = \Waypost\Reports\Palette::STATUS;
  $cell = 'padding:8px 12px; border-top:1px solid #DCE3EE;';
  $head = 'padding:9px 12px; font-size:11px; font-weight:800; letter-spacing:0.04em; text-transform:uppercase; color:#4F5B73;';
  $daily = $type === 'daily';
@endphp
@include('waypost::mail.partials.top', ['heading' => $site['name'], 'subheading' => $period_label])

  <tr><td style="padding:18px 22px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="6" border="0"><tr>
      @foreach([
        ['n' => $totals['expected'],   'l' => 'várt kör',   'fg' => '#0F1726', 'bg' => '#EEF2F8'],
        ['n' => $totals['completed'],  'l' => 'teljesítve', 'fg' => '#157F4A', 'bg' => '#D7F2E3'],
        ['n' => $totals['incomplete'], 'l' => 'hiányos',    'fg' => '#B4470F', 'bg' => '#FDE6D8'],
        ['n' => $totals['missed'],     'l' => 'elmaradt',   'fg' => '#C0262D', 'bg' => '#FBE0E1'],
      ] as $tile)
      <td width="25%" style="background:{{ $tile['bg'] }}; border-radius:12px; padding:12px 14px;">
        <div style="font-size:28px; line-height:32px; font-weight:800; color:{{ $tile['fg'] }};">{{ $tile['n'] }}</div>
        <div style="font-size:13px; font-weight:700;">{{ $tile['l'] }}</div>
      </td>
      @endforeach
    </tr></table>
  </td></tr>
  <tr><td style="padding:8px 28px 0; font-size:14px; line-height:21px; color:#4F5B73;">
    Teljesítési arány: <b style="color:#0F1726;">{{ $totals['rate'] }}</b> · {{ $totals['incidents'] }} esemény ·
    <b style="color:#6B46C1;">{{ $totals['manual'] }} nem igazolt</b> · <b style="color:#8A6100;">{{ $totals['suspicious'] }} időgyanús</b>
    @if($totals['pending']) · <b style="color:#8A6100;">{{ $totals['pending'] }} kör nincs lezárva</b>@endif
  </td></tr>

  @if(count($days))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Napok</td></tr>
  <tr><td style="padding:0 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE3EE; border-radius:12px; font-size:14px;">
      <tr style="background:#EEF2F8;">
        @foreach(['Nap', 'Várt', 'Teljesítve', 'Hiányos', 'Elmaradt', 'Arány'] as $h)
        <td style="{{ $head }}">{{ $h }}</td>
        @endforeach
      </tr>
      @foreach($days as $d)
      <tr>
        <td style="{{ $cell }} font-weight:700;">{{ $d['label'] }} <span style="color:#4F5B73; font-weight:400;">{{ $d['weekday'] }}</span></td>
        <td style="{{ $cell }}">{{ $d['expected'] }}</td>
        <td style="{{ $cell }} color:#157F4A;">{{ $d['completed'] }}</td>
        <td style="{{ $cell }} color:#B4470F;">{{ $d['incomplete'] }}</td>
        <td style="{{ $cell }} color:#C0262D;">{{ $d['missed'] }}</td>
        <td style="{{ $cell }} font-weight:700;">{{ $d['rate'] }}</td>
      </tr>
      @endforeach
    </table>
  </td></tr>
  @endif

  @if(count($problem_rounds))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Elmaradt és hiányos körök</td></tr>
  <tr><td style="padding:0 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE3EE; border-radius:12px; font-size:14px;">
      <tr style="background:#EEF2F8;">
        @foreach(['Kör', 'Állapot', 'Pontok', 'Hiányzik'] as $h)
        <td style="{{ $head }}">{{ $h }}</td>
        @endforeach
      </tr>
      @foreach($problem_rounds as $r)
      <tr>
        <td style="{{ $cell }} font-weight:800; white-space:nowrap;">@unless($daily){{ $r['day'] }} @endunless{{ $r['time'] }}@if($show_route)<div style="font-size:12px; font-weight:400; color:#4F5B73;">{{ $r['route'] }}</div>@endif</td>
        <td style="{{ $cell }}">
          <span style="display:inline-block; padding:3px 10px; border-radius:999px; background:{{ $S[$r['status']]['bg'] }}; color:{{ $S[$r['status']]['fg'] }}; font-size:12px; font-weight:800;">{{ $r['status_text'] }}</span>
        </td>
        <td style="{{ $cell }} font-weight:700;">{{ $r['done'] }}/{{ $r['total'] }}</td>
        <td style="{{ $cell }} font-size:13px; color:#4F5B73;">{{ $r['missing'] !== '' ? $r['missing'] : '–' }}</td>
      </tr>
      @endforeach
    </table>
  </td></tr>
  @endif

  @if($daily && count($shifts))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Műszakok</td></tr>
  @foreach($shifts as $s)
  <tr><td style="padding:0 28px 6px; font-size:14px; line-height:21px; color:#4F5B73;">
    <b style="color:#0F1726;">{{ $s['from'] }}–{{ $s['to'] ?? '…' }}</b> · {{ $s['guard'] }} · {{ $s['closed_by_text'] }}
  </td></tr>
  @endforeach
  @endif

  @if(count($flags))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Jelölések</td></tr>
  <tr><td style="padding:0 28px 4px; font-size:14px; line-height:21px; color:#4F5B73;">
    @foreach($flags as $f)<b style="color:#0F1726;">{{ $f['count'] }}</b> {{ $f['label'] }}@if(! $loop->last) · @endif @endforeach
  </td></tr>
  @if($daily)
  <tr><td style="padding:4px 28px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE3EE; border-radius:12px; font-size:14px;">
      @foreach($flagged_scans as $p)
      <tr>
        <td style="{{ $loop->first ? 'padding:8px 12px;' : $cell }} font-weight:700;">{{ $p['name'] }}</td>
        <td style="{{ $loop->first ? 'padding:8px 12px;' : $cell }} color:#4F5B73; white-space:nowrap;">{{ $p['time'] }} · {{ $p['method'] }}</td>
        <td style="{{ $loop->first ? 'padding:8px 12px;' : $cell }} font-size:13px; font-weight:800; color:#B4470F;">{{ $p['flag_text'] }}</td>
      </tr>
      @endforeach
    </table>
  </td></tr>
  @endif
  @endif

  @if(count($incidents))
  <tr><td style="padding:26px 28px 8px; font-size:18px; font-weight:800;">Események</td></tr>
  @unless($daily)
  <tr><td style="padding:0 28px 10px; font-size:14px; line-height:21px; color:#4F5B73;">
    @foreach($incident_categories as $c)<b style="color:#0F1726;">{{ $c['count'] }}</b> {{ $c['label'] }}@if(! $loop->last) · @endif @endforeach
  </td></tr>
  @endunless
  @foreach($incidents as $i)
  @include('waypost::mail.partials.incident', ['i' => $daily ? array_diff_key($i, ['day' => true]) : $i])
  @endforeach
  @endif

@include('waypost::mail.partials.bottom', ['note' => $daily ? null : 'A részletes jelentés a mellékelt PDF-ben van, a fotókkal együtt.'])
