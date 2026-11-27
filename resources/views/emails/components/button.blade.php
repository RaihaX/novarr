{{--
    The primary action: a bulletproof button (a filled cell; VML for
    Outlook desktop), an optional secondary link beside it, and the URL
    spelled out under it for clients that drop the button.
      $url        where it goes
      $text       its words (an arrow is added)
      $secondary  optional ['text' => …, 'url' => …] link beside it
      $fallback   spell the URL out under the button (default true)
--}}
@php($T = \App\Mail\MailTheme::class)
@php($vmlWidth = max(150, 56 + (mb_strlen($text) + 2) * 9))
<table role="presentation" cellpadding="0" cellspacing="0" border="0" data-mail-button style="border-collapse: separate; margin: 22px 0 {{ ($fallback ?? true) ? '14px' : '4px' }};">
    <tr>
        <td align="center" bgcolor="#4F5CE6" class="nv-button-cell" style="background-color: #4F5CE6; border-radius: 4px;">
            <!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:42px;v-text-anchor:middle;width:{{ $vmlWidth }}px;" arcsize="10%" stroke="f" fillcolor="#4F5CE6"><w:anchorlock/><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">{{ $text }} &rarr;</center></v:roundrect><![endif]-->
            <!--[if !mso]><!--><a href="{{ $url }}" class="nv-button" style="display: inline-block; padding: 11px 24px; font-family: {{ $T::SANS }}; font-size: 15px; font-weight: 600; line-height: 20px; color: #FFFFFF; text-decoration: none; border-radius: 4px;">{{ $text }} &rarr;</a><!--<![endif]-->
        </td>
        @if (!empty($secondary['url']))
            <td style="padding-left: 18px; font-family: {{ $T::SANS }}; font-size: 14px; font-weight: 600; white-space: nowrap;">
                <a class="nv-link" href="{{ $secondary['url'] }}" style="color: #8EA2FF; text-decoration: none;">{{ $secondary['text'] }}</a>
            </td>
        @endif
    </tr>
</table>
@if ($fallback ?? true)
    <p class="nv-muted" style="margin: 0 0 4px; font-family: {{ $T::SANS }}; font-size: 12px; line-height: 1.6; color: #6B7684;">If the button does not work, copy this address into your browser:</p>
    <p style="margin: 0; font-family: {{ $T::MONO }}; font-size: 12px; line-height: 1.6; word-break: break-all;"><a class="nv-link" href="{{ $url }}" style="color: #8EA2FF; text-decoration: none;">{{ $url }}</a></p>
@endif
