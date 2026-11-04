{{-- An incident of a report e-mail, with its photos' signed links. Variables: $i (ReportContent's incident). --}}
  <tr><td style="padding:0 28px 10px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE3EE; border-radius:12px;"><tr>
      <td valign="top" style="padding:14px;">
        <div style="font-size:15px; font-weight:800;">@isset($i['day']){{ $i['day'] }} @endisset{{ $i['time'] }} · {{ $i['category'] }}@if($i['manual_point'])<span style="color:#6B46C1;"> · nem igazolt pont</span>@endif</div>
        @if($i['place'] !== null || $i['accuracy'] !== null)
        <div style="font-size:13px; color:#4F5B73; padding-top:2px;">{{ $i['place'] ?? 'Nincs közeli pont' }}@if($i['accuracy'] !== null) · GPS ±{{ $i['accuracy'] }} m @endif</div>
        @endif
        @if($i['note'])<div style="font-size:14px; line-height:21px; padding-top:4px;">„{{ $i['note'] }}”</div>@endif
        @if($i['photos_missing'])<div style="font-size:13px; color:#C0262D; padding-top:4px;">{{ $i['photos_missing'] }} fotó még nem érkezett meg.</div>@endif
      </td>
      <td valign="top" align="right" style="padding:14px 14px 14px 0; white-space:nowrap;">
        @foreach(array_slice($i['photos'], 0, 3) as $p)
          <a href="{{ $p['url'] }}"><img src="{{ $p['thumb_url'] }}" width="72" height="72" alt="Fotó" style="border-radius:8px; display:inline-block; border:0; object-fit:cover;"></a>
        @endforeach
      </td>
    </tr></table>
  </td></tr>
