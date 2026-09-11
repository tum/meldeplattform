<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dormant administrator access revoked</title>
</head>
<body style="font-family: Arial, sans-serif; color: #20252A; background: #F7F7F7; margin: 0; padding: 24px;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border-top: 4px solid #E37222; border-radius: 6px; padding: 24px;">
        <h1 style="color: #072140; font-size: 20px; margin-top: 0;">
            Inaktiver Admin-Zugriff entzogen / Dormant administrator access revoked
        </h1>

        <p style="font-size: 15px; line-height: 1.55;">
            Die folgenden Administrator-Zugänge wurden automatisch entzogen, weil seit
            mehr als {{ $windowDays }} Tagen kein Login erfolgte (bzw. ein vorgemerkter
            Zugang nie genutzt wurde). Der Zugriff kann in der User-Verwaltung jederzeit
            neu vergeben werden.
        </p>
        <p style="font-size: 15px; line-height: 1.55; color: #6A757E;">
            The following administrator accounts were revoked automatically because they
            had not been used for more than {{ $windowDays }} days (or a pre-assigned
            account was never used). Access can be re-granted in user management at any time.
        </p>

        <table style="width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 14px;">
            <thead>
                <tr style="text-align: left; color: #6A757E;">
                    <th style="padding: 6px 8px; border-bottom: 1px solid #E5E5E5;">User-ID</th>
                    <th style="padding: 6px 8px; border-bottom: 1px solid #E5E5E5;">Letzter Login / Last login</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($revoked as $row)
                    <tr>
                        <td style="padding: 6px 8px; border-bottom: 1px solid #F0F0F0; font-family: monospace;">{{ $row['uid'] }}</td>
                        <td style="padding: 6px 8px; border-bottom: 1px solid #F0F0F0;">{{ $row['last_login'] ?? ($row['kind'] === 'never_logged_in' ? 'nie / never' : '—') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin-top: 20px;">
            <a href="{{ $usersUrl }}" style="display: inline-block; background: #3070B3; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 4px; font-size: 15px;">
                User-Verwaltung öffnen / Open user management
            </a>
        </p>
    </div>
</body>
</html>
