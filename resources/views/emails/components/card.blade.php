{{--
    The hero card: a cover thumbnail (when there is one) beside a title and
    a meta line, on an inset panel with a 2px tone rule on the left.
      $title  the book / series / thing
      $meta   optional line under it ("Book · Brandon Sanderson")
      $cover  optional absolute image URL
      $url    optional link on the title and cover
      $tone   info | success | warning | danger (the left rule; default info)
      $chip   optional status chip beside the meta ("IN THE LIBRARY")
--}}
@php($T = \App\Mail\MailTheme::class)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" data-mail-card style="width: 100%; background-color: #0F1216; border: 1px solid #262D38; border-left: 2px solid {{ $T::tone($tone ?? null) }}; border-collapse: separate; margin: 4px 0 18px;">
    <tr>
        @if (!empty($cover))
            <td width="64" valign="top" bgcolor="#0F1216" class="nv-ground" style="width: 64px; vertical-align: top; background-color: #0F1216; padding: 14px 0 14px 14px;">
                @if (!empty($url))<a href="{{ $url }}" style="text-decoration: none;">@endif<img src="{{ $cover }}" width="64" alt="" data-mail-cover style="display: block; width: 64px; height: auto; max-height: 100px; border: 1px solid #262D38; border-radius: 3px; background-color: #1C222A;">@if (!empty($url))</a>@endif
            </td>
        @endif
        <td valign="middle" bgcolor="#0F1216" class="nv-ground" style="vertical-align: middle; background-color: #0F1216; padding: 14px 16px;">
            <div style="font-family: {{ $T::SERIF }}; font-size: 17px; line-height: 1.35; font-weight: 700;">
                @if (!empty($url))
                    <a class="nv-text" href="{{ $url }}" style="color: #E8EBF0; text-decoration: none;">{{ $title }}</a>
                @else
                    <span class="nv-text" style="color: #E8EBF0;">{{ $title }}</span>
                @endif
            </div>
            @if (!empty($meta) || !empty($chip))
                <div class="nv-muted" style="padding-top: 5px; font-family: {{ $T::MONO }}; font-size: 12px; line-height: 1.55; color: #8B95A5;">
                    @if (!empty($meta)){{ $meta }}@endif
                    @if (!empty($chip))@if (!empty($meta))&nbsp; @endif<span style="{{ $T::toneChipStyle($tone ?? null) }}">{{ $chip }}</span>@endif
                </div>
            @endif
        </td>
    </tr>
</table>
