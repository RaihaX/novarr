{{--
    A small two-column facts table (label: value).
      $facts  [label => value] or [[label, value, mono?], …]
--}}
@php($T = \App\Mail\MailTheme::class)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" data-mail-facts style="width: 100%; background-color: #0F1216; border: 1px solid #262D38; border-collapse: separate; margin: 4px 0 18px;">
    @foreach ($facts as $key => $fact)
        @php([$factLabel, $factValue, $factMono] = is_array($fact) ? [$fact[0], $fact[1], $fact[2] ?? false] : [$key, $fact, false])
        <tr>
            <td width="34%" valign="top" class="nv-muted" style="width: 34%; vertical-align: top; padding: {{ $loop->first ? '12px' : '4px' }} 12px {{ $loop->last ? '12px' : '4px' }} 14px; font-family: {{ $T::SANS }}; font-size: 13px; line-height: 1.6; color: #8B95A5;">{{ $factLabel }}</td>
            <td valign="top" class="nv-text" style="vertical-align: top; padding: {{ $loop->first ? '12px' : '4px' }} 14px {{ $loop->last ? '12px' : '4px' }} 0; font-family: {{ $factMono ? $T::MONO : $T::SANS }}; font-size: {{ $factMono ? '13px' : '14px' }}; line-height: 1.6; color: #E8EBF0; word-break: break-word;">{{ $factValue }}</td>
        </tr>
    @endforeach
</table>
