{{--
    The ONE frame of every Novarr email (the daily summary's dark "Serial"
    look): preheader, header (four-bar mark + NOVARR. wordmark, 2px accent
    rule, an optional mono label on the right), the body, and the footer
    (links + the reason line). Mails @extends it and compose the body from
    emails/components/* (heading, card, quote, list, facts, button, code).

    Variables (all optional):
      $mailTitle    <title> of the document (default "Novarr")
      $preheader    the inbox preview line
      $headerAside  the mono label right of the wordmark (the daily summary's date)
      $footer       why the recipient gets this email
      $footerLinks  [label => url] links above the reason line ("Open Novarr")

    Sections:
      content       the body, inside the padded surface panel (most mails)
      rows          instead of content: whole <tr> rows (the daily summary)
      footer_note   raw HTML in place of the escaped $footer line

    Every value is inlined (App\Mail\MailTheme); the <style> block only
    serves clients that repaint dark mail (Outlook.com's [data-ogsc]).
    Max 600px; Outlook gets a fixed 600px ghost table.
--}}
@php
    $T = \App\Mail\MailTheme::class;
    $sans = $T::SANS;
    $mono = $T::MONO;
    $footerLinks = array_filter((array) ($footerLinks ?? []));
@endphp
<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    {{-- Dark is the canonical Novarr theme; tell the client so it doesn't try to "fix" it. --}}
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ $mailTitle ?? 'Novarr' }}</title>
    <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
    <style type="text/css">
        /* Everything that matters is inline; this block exists purely for
           clients that repaint dark mail (Outlook.com rewrites colours
           behind [data-ogsc]/[data-ogsb]) and for narrow screens. */
        [data-ogsc] .nv-ground { background-color: #0F1216 !important; }
        [data-ogsc] .nv-surface { background-color: #161A20 !important; }
        [data-ogsc] .nv-text { color: #E8EBF0 !important; }
        [data-ogsc] .nv-body { color: #D8DDE6 !important; }
        [data-ogsc] .nv-muted { color: #8B95A5 !important; }
        [data-ogsc] .nv-link { color: #8EA2FF !important; }
        [data-ogsc] .nv-button { color: #FFFFFF !important; }
        [data-ogsb] .nv-ground { background-color: #0F1216 !important; }
        [data-ogsb] .nv-surface { background-color: #161A20 !important; }
        [data-ogsb] .nv-button-cell { background-color: #4F5CE6 !important; }
        @media only screen and (max-width: 480px) {
            .nv-pad { padding-left: 16px !important; padding-right: 16px !important; }
            .nv-code { font-size: 26px !important; letter-spacing: 0.14em !important; }
        }
    </style>
</head>
<body class="nv-ground" style="margin: 0; padding: 0; width: 100%; background-color: #0F1216; color: #E8EBF0; font-family: {{ $sans }}; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">

{{-- Inbox preview line; hidden everywhere else. --}}
@if (!empty($preheader))
<div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" data-mail-layout="novarr" style="width: 100%; background-color: #0F1216; margin: 0; padding: 0;">
    <tr>
        <td align="center" bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 16px 8px 24px;">
            <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" style="width: 100%; max-width: 600px; background-color: #0F1216; border: 1px solid #262D38; border-collapse: separate;">

                {{-- ── Header: Serial mark + wordmark left, optional mono label right, 2px accent rule under ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground nv-pad" data-mail-header style="background-color: #0F1216; padding: 18px 20px; border-bottom: 2px solid #6470FF;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="left" valign="middle" style="vertical-align: middle;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
                                        <tr>
                                            {{-- "Serial" mark on a 26px grid, drawn with table cells (images and
                                                 inline SVG are unreliable in Gmail/Outlook): four 3px bars with
                                                 2px gaps, 22/22/15/8 wide, the last one amber. --}}
                                            <td width="22" valign="middle" style="width: 22px; vertical-align: middle; padding-right: 12px;">
                                                <table role="presentation" width="22" cellpadding="0" cellspacing="0" border="0" style="width: 22px; border-collapse: collapse;">
                                                    <tr><td height="4" style="height: 4px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                    @foreach ([[22, '#6470FF'], [22, '#6470FF'], [15, '#6470FF'], [8, '#F0B429']] as [$w, $c])
                                                        <tr>
                                                            <td align="left" style="font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">
                                                                <table role="presentation" width="{{ $w }}" cellpadding="0" cellspacing="0" border="0" style="width: {{ $w }}px; border-collapse: collapse;">
                                                                    <tr><td width="{{ $w }}" height="3" bgcolor="{{ $c }}" style="width: {{ $w }}px; height: 3px; background-color: {{ $c }}; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        @unless ($loop->last)
                                                            <tr><td height="2" style="height: 2px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                        @endunless
                                                    @endforeach
                                                    <tr><td height="4" style="height: 4px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                </table>
                                            </td>
                                            <td valign="middle" style="vertical-align: middle;">
                                                <span class="nv-text" style="font-family: {{ $sans }}; font-size: 14px; font-weight: 700; letter-spacing: 0.16em; color: #E8EBF0;">NOVARR<span style="color: #F0B429;">.</span></span>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                @if (!empty($headerAside))
                                    <td align="right" valign="middle" class="nv-muted" style="vertical-align: middle; font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.08em; color: #8B95A5; white-space: nowrap;">
                                        {{ $headerAside }}
                                    </td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ── Body ── --}}
@hasSection('rows')
                @yield('rows')
@else
                <tr>
                    <td bgcolor="#161A20" class="nv-surface nv-pad" style="background-color: #161A20; padding: 26px 24px 28px;">
                        @yield('content')
                    </td>
                </tr>
@endif

                {{-- ── Footer: links, then why this email came ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground nv-pad" data-mail-footer style="background-color: #0F1216; padding: 16px 20px 20px; border-top: 1px solid #262D38;">
                        @if ($footerLinks !== [])
                            <div style="font-family: {{ $sans }}; font-size: 13px; line-height: 1.6; font-weight: 600;">
                                @foreach ($footerLinks as $label => $href)<a class="nv-link" href="{{ $href }}" style="color: #8EA2FF; text-decoration: none;">{{ $label }}</a>@unless ($loop->last)<span style="color: #5B6472;"> &nbsp;·&nbsp; </span>@endunless @endforeach
                            </div>
                        @endif
                        <div class="nv-muted" style="{{ $footerLinks !== [] ? 'padding-top: 6px; ' : '' }}font-family: {{ $mono }}; font-size: 11px; line-height: 1.6; color: #6B7684;">
@hasSection('footer_note')
                            @yield('footer_note')
@else
                            {{ $footer ?? 'Sent by Novarr.' }}
@endif
                        </div>
                    </td>
                </tr>

            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
