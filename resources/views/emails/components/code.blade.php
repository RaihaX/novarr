{{--
    A one-time code: large mono digits on an inset panel, easy to read and
    to select (no link, no button).
      $code  the code
--}}
@php($T = \App\Mail\MailTheme::class)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" data-mail-code style="background-color: #0F1216; border: 1px solid #262D38; border-left: 2px solid #6470FF; border-collapse: separate; margin: 4px 0 18px;">
    <tr>
        <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 14px 22px 14px 20px;">
            <span class="nv-text nv-code" style="font-family: {{ $T::MONO }}; font-size: 32px; line-height: 40px; font-weight: 700; letter-spacing: 0.22em; color: #E8EBF0; white-space: nowrap;">{{ $code }}</span>
        </td>
    </tr>
</table>
