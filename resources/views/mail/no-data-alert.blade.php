{{--
  "Nincs adat" alert e-mail (spec: „Nincs adat” figyelmeztetés). The variables are
  Waypost\Jobs\SendNoDataAlerts::content()'s.
--}}
@include('waypost::mail.partials.top', ['heading' => $site['name'], 'subheading' => 'Nem érkezik adat a telephelyről', 'incomplete' => []])

  <tr><td style="padding:12px 28px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td style="background:#FBE0E1; border-radius:12px; padding:14px; font-size:15px; line-height:23px;">
        <b style="color:#C0262D;">Nincs adat.</b>
        @if($last_seen !== null)
          A telephelyi telefon {{ $last_seen }} óta nem jelentkezett.
        @else
          A telephelyi telefon a hozzárendelése óta nem jelentkezett.
        @endif
        Az utolsó várt kör {{ $round_label }} volt, a határideje {{ $deadline }}; azóta {{ $elapsed }} telt el.
      </td>
    </tr></table>
  </td></tr>

  <tr><td style="padding:22px 28px 6px; font-size:18px; font-weight:800;">Mit érdemes megnézni?</td></tr>
  <tr><td style="padding:0 28px; font-size:14px; line-height:22px; color:#4F5B73;">
    Be van-e kapcsolva és töltőn van-e a telefon, van-e hálózata, és fut-e rajta a Waypost. Ha az őr dolgozik, de a telefon nem tud szinkronizálni, a körök adatai a telefonon megmaradnak, és a kapcsolat helyreálltával megérkeznek.
  </td></tr>

  <tr><td style="height:24px; line-height:24px; font-size:0;">&nbsp;</td></tr>
  <tr><td style="padding:24px 28px; background:#EEF2F8; border-radius:0 0 16px 16px; font-size:12px; line-height:19px; color:#4F5B73;">
    Ezt a figyelmeztetést a Waypost rendszer küldte automatikusan, menetrendi ablakonként legfeljebb egyszer, a telephely minden supervisorának. A várakozási idő ({{ $after }}) az appban állítható: Telephely › Jelentések.
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
