<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $topicName }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #20252A; background: #F7F7F7; margin: 0; padding: 24px;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border-top: 4px solid #E37222; border-radius: 6px; padding: 24px;">
        <h1 style="color: #072140; font-size: 20px; margin-top: 0;">
            Fristen-Erinnerung / Deadline reminder — {{ $topicName }}
        </h1>

        {{-- Notification-only: report IDs and deadlines only, never content. --}}
        <p style="font-size: 15px; line-height: 1.55;">
            Die folgenden Meldungen nähern sich einer gesetzlichen Frist oder haben sie überschritten
            (Eingangsbestätigung: 7 Tage; Rückmeldung: 3 Monate). Bitte öffnen Sie das gesicherte Dashboard.
        </p>
        <p style="font-size: 15px; line-height: 1.55; color: #6A757E;">
            The following reports are approaching or past a statutory deadline
            (acknowledgement: 7 days; feedback: 3 months). Please open the secure dashboard to act.
        </p>

        <table style="width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 14px;">
            <thead>
                <tr style="text-align: left; color: #6A757E;">
                    <th style="padding: 6px 8px; border-bottom: 1px solid #E5E5E5;">Report</th>
                    <th style="padding: 6px 8px; border-bottom: 1px solid #E5E5E5;">Frist / Deadline</th>
                    <th style="padding: 6px 8px; border-bottom: 1px solid #E5E5E5;">Fällig / Due</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td style="padding: 6px 8px; border-bottom: 1px solid #F0F0F0;">#{{ $item['id'] }}</td>
                        <td style="padding: 6px 8px; border-bottom: 1px solid #F0F0F0;">
                            {{ $item['type'] === 'acknowledgement' ? 'Eingangsbestätigung / Acknowledgement' : 'Rückmeldung / Feedback' }}
                        </td>
                        <td style="padding: 6px 8px; border-bottom: 1px solid #F0F0F0; {{ $item['overdue'] ? 'color: #C4262E; font-weight: 600;' : '' }}">
                            {{ $item['due'] }}@if ($item['overdue']) — überfällig / overdue @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin-top: 24px;">
            <a href="{{ $dashboardUrl }}"
               style="display: inline-block; background: #3070B3; color: #fff; padding: 10px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                Zum Dashboard / Open dashboard
            </a>
        </p>

        <p style="color: #6A757E; font-size: 12px; margin-top: 32px;">
            TUM SafeSignal · Whistleblowing &amp; IT Security Reporting System · Technische Universität München
        </p>
    </div>
</body>
</html>
