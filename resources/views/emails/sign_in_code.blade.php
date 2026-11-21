<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    {{-- Same frame as emails/newchapters: dark is the canonical Novarr theme. --}}
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Your Novarr sign-in code</title>
    <style type="text/css">
        [data-ogsc] .nv-ground { background-color: #0F1216 !important; }
        [data-ogsc] .nv-surface { background-color: #161A20 !important; }
        [data-ogsc] .nv-text { color: #E8EBF0 !important; }
        [data-ogsc] .nv-muted { color: #8B95A5 !important; }
        [data-ogsc] .nv-link { color: #8EA2FF !important; }
        [data-ogsb] .nv-ground { background-color: #0F1216 !important; }
        [data-ogsb] .nv-surface { background-color: #161A20 !important; }
    </style>
</head>
@php
    /** App\Mail\SignInCode: $name, $code, $minutes, $ip, $purpose. Every value inlined. */
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Courier New', monospace";
    $body = "font-family: {$sans}; font-size: 15px; line-height: 1.6; color: #D8DDE6;";
@endphp
<body class="nv-ground" style="margin: 0; padding: 0; width: 100%; background-color: #0F1216; color: #E8EBF0; font-family: {{ $sans }}; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">

{{-- Inbox preview line; hidden everywhere else. --}}
<div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;">Your sign-in code is {{ $code }}.&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" style="width: 100%; background-color: #0F1216; margin: 0; padding: 0;">
    <tr>
        <td align="center" bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 16px 8px 24px;">
            <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" style="width: 100%; max-width: 600px; background-color: #0F1216; border: 1px solid #262D38; border-collapse: separate;">

                {{-- Header: Serial mark + wordmark, 2px accent rule under (as in the daily summary) --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 18px 20px; border-bottom: 2px solid #6470FF;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
                            <tr>
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
                </tr>

                {{-- Body --}}
                <tr>
                    <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 24px 20px 26px;">
                        <h1 class="nv-text" style="margin: 0 0 14px; font-family: {{ $sans }}; font-size: 20px; line-height: 1.3; font-weight: 600; letter-spacing: -0.01em; color: #E8EBF0;">Hi {{ $name }},</h1>
                        <p style="margin: 0 0 12px; {{ $body }}">Your sign-in code is</p>
                        <p class="nv-text" style="margin: 0 0 18px; font-family: {{ $mono }}; font-size: 30px; line-height: 1.2; font-weight: 700; letter-spacing: 0.2em; color: #E8EBF0;">{{ $code }}</p>
                        <p style="margin: 0 0 16px; {{ $body }}">Enter it {{ $purpose }}. It works for {{ $minutes }} minutes, once.</p>
                        <p style="margin: 0; {{ $body }}">It was asked for from the address <span style="font-family: {{ $mono }};">{{ $ip }}</span>. If that was not you, someone knows your password: change it on your Account page, or ask the person who runs this Novarr.</p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 16px 20px 20px; border-top: 1px solid #262D38;">
                        <div class="nv-muted" style="font-family: {{ $sans }}; font-size: 12px; line-height: 1.6; color: #6B7684;">
                            Never share this code. Novarr never asks for it anywhere else.
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
