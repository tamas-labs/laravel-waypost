{{--
  The opening of every report e-mail: 600 px wide, table layout, inline styles (Outlook, Gmail), the light theme's
  colours (design src/theme/tokens.ts, palette.light). Variables: $title (the report's kind), $heading, $subheading,
  $incomplete (list of reasons the data may not be complete yet).
--}}
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>{{ $title }} – {{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background:#EEF2F8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#EEF2F8;">
<tr><td align="center" style="padding:24px 12px;">

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background:#FFFFFF; border:1px solid #DCE3EE; border-radius:16px; font-family: Manrope, Arial, Helvetica, sans-serif; color:#0F1726;">

  <tr><td style="background:#0F1420; padding:18px 28px; border-radius:16px 16px 0 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td style="font-family: Manrope, Arial, Helvetica, sans-serif; font-size:16px; font-weight:800; color:#E8ECF5;">Waypost</td>
      <td align="right" style="font-family: Manrope, Arial, Helvetica, sans-serif; font-size:13px; font-weight:700; color:#9AA6BF;">{{ $title }}</td>
    </tr></table>
  </td></tr>

  <tr><td style="padding:26px 28px 6px;">
    <div style="font-size:26px; line-height:32px; font-weight:800;">{{ $heading }}</div>
    <div style="font-size:15px; line-height:22px; color:#4F5B73;">{{ $subheading }}</div>
  </td></tr>

  @if(count($incomplete))
  <tr><td style="padding:12px 28px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td style="background:#FBE0E1; border-radius:12px; padding:12px 14px; font-size:14px; line-height:21px;">
        <b style="color:#C0262D;">Nem teljes adat:</b> {{ implode('; ', $incomplete) }}. A jelentés a kiküldéskor ismert adatokból készült.
      </td>
    </tr></table>
  </td></tr>
  @endif
