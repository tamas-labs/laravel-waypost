{{-- The closing of every report e-mail. Variables: $chain (the state of the chain), $photoLinkDays, $note (optional). --}}
  <tr><td style="padding:26px 28px 6px; font-size:18px; font-weight:800;">A napló állapota</td></tr>
  <tr><td style="padding:0 28px; font-size:14px; line-height:21px; color:#4F5B73;">
    @if($chain['ok'])
      <b style="color:#157F4A;">Rendben.</b> A telefon minden bejegyzése ellenőrizve.
    @else
      <b style="color:#C0262D;">{{ ucfirst($chain['text']) }}.</b>
      @if($chain['diagnostics']) A telefon {{ $chain['diagnostics'] }} elutasított bejegyzést jelzett. @endif
    @endif
  </td></tr>

  <tr><td style="height:24px; line-height:24px; font-size:0;">&nbsp;</td></tr>
  <tr><td style="padding:24px 28px; background:#EEF2F8; border-radius:0 0 16px 16px; font-size:12px; line-height:19px; color:#4F5B73;">
    @isset($note){{ $note }}<br>@endisset
    A fotók linkje a felvételüktől számított {{ $photoLinkDays }} napig érvényes. A jelölések nem döntések: az eltéréseket a supervisor bírálja el.<br>
    Ezt a jelentést a Waypost rendszer küldte automatikusan. Beállítás: app › Telephely › Jelentések.
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
