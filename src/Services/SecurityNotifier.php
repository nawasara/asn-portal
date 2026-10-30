<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Email for the account portal: one-time codes, and "something changed on
 * your account" alerts, through nawasara/notification (`Notify`, SMTP from
 * the Vault group `smtp`).
 *
 * Codes are sent synchronously (the user is waiting) and marked
 * ->sensitive() so the code does not stay in the notification log. Alerts
 * are queued; a failed alert must never fail the action it reports on.
 */
class SecurityNotifier
{
    public function available(): bool
    {
        return class_exists(\Nawasara\Notification\Facades\Notify::class);
    }

    /**
     * @throws RuntimeException when the code could not be delivered
     */
    public function sendCode(string $email, string $code, int $ttlMinutes, string $purposeLabel): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Pengiriman email belum tersedia.');
        }

        $body = view('nawasara-asn-portal::emails.code', [
            'code' => $code,
            'ttlMinutes' => $ttlMinutes,
            'purposeLabel' => $purposeLabel,
        ])->render();

        try {
            \Nawasara\Notification\Facades\Notify::to($email)
                ->channel('email')
                ->subject("Kode {$purposeLabel} Akun ASN")
                ->body($body)
                ->context(['source' => 'asn-portal', 'purpose' => $purposeLabel])
                ->sensitive()
                ->sync()
                ->send();
        } catch (\Throwable $e) {
            Log::warning('asn-portal: code email failed', ['reason' => $e->getMessage()]);

            throw new RuntimeException('Email kode gagal dikirim.');
        }
    }

    /**
     * "Something changed on your account" — sent to the account's email so a
     * change the owner did not make is noticed.
     *
     * @param  list<string>  $lines
     */
    public function alert(?string $email, string $subject, array $lines): void
    {
        if ($email === null || $email === '' || ! $this->available()) {
            return;
        }

        try {
            \Nawasara\Notification\Facades\Notify::to($email)
                ->channel('email')
                ->subject($subject)
                ->body(view('nawasara-asn-portal::emails.alert', ['subject' => $subject, 'lines' => $lines])->render())
                ->context(['source' => 'asn-portal'])
                ->send();
        } catch (\Throwable $e) {
            Log::warning('asn-portal: alert email failed', ['reason' => $e->getMessage()]);
        }
    }
}
