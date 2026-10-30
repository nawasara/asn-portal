<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px 0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">
                <tr><td style="padding:20px 28px;border-bottom:1px solid #f0f0f0;">
                    <div style="font-size:16px;font-weight:bold;">Akun ASN</div>
                    <div style="font-size:13px;color:#6b7280;">Pemberitahuan keamanan</div>
                </td></tr>
                <tr><td style="padding:28px;font-size:15px;line-height:1.6;color:#374151;">
                    <p style="margin:0 0 12px;font-weight:bold;">{{ $subject }}</p>
                    @foreach ($lines as $line)
                        <p style="margin:0 0 8px;">{{ $line }}</p>
                    @endforeach
                    <p style="margin:16px 0 0;font-size:13px;color:#6b7280;">Bila bukan Anda yang melakukannya, segera ganti kata sandi di SimasHebat, buka Akun ASN, dan keluarkan perangkat yang tidak Anda kenali. Butuh bantuan? Hubungi RAKACA.</p>
                </td></tr>
                <tr><td style="padding:16px 28px;background:#fafafa;font-size:12px;color:#9ca3af;text-align:center;">
                    Email otomatis dari Akun ASN · Dinas Komunikasi, Informatika dan Statistik Kabupaten Ponorogo
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
