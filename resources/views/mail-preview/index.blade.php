<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Email previews · Novarr</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #0F1216; color: #E8EBF0; font: 15px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 16px 48px; }
        h1 { margin: 0 0 6px; font-size: 22px; font-weight: 600; }
        p.lead { margin: 0 0 28px; color: #8B95A5; }
        h2 { margin: 28px 0 10px; font: 600 11px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .12em; text-transform: uppercase; color: #F0B429; }
        ul { list-style: none; margin: 0; padding: 0; border: 1px solid #262D38; background: #161A20; }
        li { display: flex; gap: 12px; align-items: baseline; justify-content: space-between; padding: 12px 16px; }
        li + li { border-top: 1px solid #262D38; }
        .label { font-weight: 600; }
        .subject { display: block; color: #8B95A5; font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }
        .links { white-space: nowrap; font-size: 14px; font-weight: 600; }
        a { color: #8EA2FF; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .sep { color: #5B6472; padding: 0 6px; }
        .back { display: inline-block; margin-bottom: 18px; font-size: 14px; }
    </style>
</head>
<body>
<main>
    <a class="back" href="{{ route('settings.index') }}">&larr; Settings</a>
    <h1>Email previews</h1>
    <p class="lead">Every email Novarr sends, with sample data. Nothing is sent. Each opens as it arrives in an inbox; "Text" is its plain-text part.</p>

    @foreach ($groups as $group => $mails)
        <h2>{{ $group }}</h2>
        <ul>
            @foreach ($mails as $mail)
                <li data-mail-preview="{{ $mail['name'] }}">
                    <div>
                        <a class="label" href="{{ route('mail_preview.show', $mail['name']) }}">{{ $mail['label'] }}</a>
                        <span class="subject">{{ $mail['subject'] }}</span>
                    </div>
                    <div class="links">
                        <a href="{{ route('mail_preview.show', $mail['name']) }}">HTML</a><span class="sep">·</span><a href="{{ route('mail_preview.show', ['name' => $mail['name'], 'part' => 'text']) }}">Text</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endforeach
</main>
</body>
</html>
