<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $heading }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #20252A; background: #F7F7F7; margin: 0; padding: 24px;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border-top: 4px solid #3070B3; border-radius: 6px; padding: 24px;">
        <h1 style="color: #072140; font-size: 20px; margin-top: 0;">{{ $heading }}</h1>

        <div style="font-size: 15px; line-height: 1.55;">
            {!! $bodyHtml !!}
        </div>

        <p style="margin-top: 24px;">
            <a href="{{ $linkUrl }}"
               style="display: inline-block; background: #3070B3; color: #fff; padding: 10px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                {{ app()->getLocale() === 'de' ? 'Zur Meldung' : 'Open report' }}
            </a>
        </p>

        <p style="color: #6A757E; font-size: 12px; margin-top: 32px;">
            TUM Meldeplattform · Technische Universität München
        </p>
    </div>
</body>
</html>
