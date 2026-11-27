{{--
    A list of items on an inset panel, one row each: optional thumbnail,
    title (linked when there is a url), meta line, and a status chip.
      $items  [['title', 'meta'?, 'status'?, 'tone'?, 'url'?, 'cover'?], …]
      $label  optional label above the panel ("What arrived")
      $more   optional line under the last row ("and 3 more")
--}}
@php($T = \App\Mail\MailTheme::class)
@if (!empty($label))
    <p class="nv-muted" style="margin: 0 0 8px; {{ $T::label() }} line-height: 14px; color: #8B95A5;">{{ $label }}</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" data-mail-list style="width: 100%; background-color: #0F1216; border: 1px solid #262D38; border-collapse: separate; margin: 0 0 18px;">
    @foreach ($items as $item)
        @php($item = (array) $item)
        <tr data-mail-item>
            <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 11px 14px; {{ $loop->first ? '' : 'border-top: 1px solid #262D38;' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        @if (!empty($item['cover']))
                            <td width="36" valign="top" style="width: 36px; vertical-align: top; padding-right: 12px;">
                                <img src="{{ $item['cover'] }}" width="36" alt="" style="display: block; width: 36px; height: auto; max-height: 56px; border: 1px solid #262D38; border-radius: 2px; background-color: #1C222A;">
                            </td>
                        @endif
                        <td valign="top" style="vertical-align: top;">
                            <div style="font-family: {{ $T::SANS }}; font-size: 14px; line-height: 1.45; font-weight: 600;">
                                @if (!empty($item['url']))
                                    <a class="nv-text" href="{{ $item['url'] }}" style="color: #E8EBF0; text-decoration: none;">{{ $item['title'] }}</a>
                                @else
                                    <span class="nv-text" style="color: #E8EBF0;">{{ $item['title'] }}</span>
                                @endif
                            </div>
                            @if (!empty($item['meta']))
                                <div class="nv-muted" style="padding-top: 3px; font-family: {{ $T::SANS }}; font-size: 13px; line-height: 1.5; color: #8B95A5;">{{ $item['meta'] }}</div>
                            @endif
                        </td>
                        @if (!empty($item['status']))
                            <td align="right" valign="top" style="vertical-align: top; padding-left: 10px; white-space: nowrap;">
                                <span style="{{ $T::toneChipStyle($item['tone'] ?? null) }}">{{ $item['status'] }}</span>
                            </td>
                        @endif
                    </tr>
                </table>
            </td>
        </tr>
    @endforeach
    @if (!empty($more))
        <tr>
            <td bgcolor="#0F1216" class="nv-muted" style="background-color: #0F1216; padding: 10px 14px; border-top: 1px solid #262D38; font-family: {{ $T::SANS }}; font-size: 13px; line-height: 1.5; color: #8B95A5;">{{ $more }}</td>
        </tr>
    @endif
</table>
