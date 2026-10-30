<?php

namespace Nawasara\AsnPortal\Support;

/**
 * One activity feed from two sources: Keycloak login events, and the
 * portal's own audit entries (MFA reset, WhatsApp change, credential
 * removed). Labels are user-facing, so they are Indonesian.
 */
final class ActivityPresenter
{
    /** Keycloak event type → [label, kind]. Types not listed are dropped. */
    public const KEYCLOAK_EVENTS = [
        'LOGIN' => ['Masuk', 'sign_in'],
        'LOGIN_ERROR' => ['Gagal masuk', 'failure'],
        'LOGOUT' => ['Keluar', 'sign_out'],
        'UPDATE_TOTP' => ['Autentikator dipasang', 'change'],
        'REMOVE_TOTP' => ['Autentikator dihapus', 'change'],
        'UPDATE_CREDENTIAL' => ['Cara masuk diperbarui', 'change'],
        'REMOVE_CREDENTIAL' => ['Cara masuk dihapus', 'change'],
        'UPDATE_PASSWORD' => ['Kata sandi diganti', 'change'],
        'UPDATE_PROFILE' => ['Profil diperbarui', 'change'],
    ];

    /** Portal audit description → [label, kind]. */
    public const PORTAL_EVENTS = [
        'mfa_reset' => ['Verifikasi dua langkah direset', 'change'],
        'whatsapp_changed' => ['Nomor WhatsApp diubah', 'change'],
        'credential_removed' => ['Cara masuk dihapus dari Akun ASN', 'change'],
        'credential_renamed' => ['Nama cara masuk diubah', 'change'],
        'session_revoked' => ['Perangkat dikeluarkan', 'change'],
        'other_sessions_revoked' => ['Keluar dari semua perangkat lain', 'change'],
        'webmail_opened' => ['Membuka webmail', 'sign_in'],
        'mailbox_password_changed' => ['Kata sandi email dinas diganti', 'change'],
        'mailbox_auto_reply_set' => ['Balasan otomatis email diatur', 'change'],
        'mailbox_auto_reply_cleared' => ['Balasan otomatis email dimatikan', 'change'],
        'mailbox_forwarder_added' => ['Penerusan email ditambahkan', 'change'],
        'mailbox_forwarder_removed' => ['Penerusan email dihapus', 'change'],
        'vpn_config_downloaded' => ['Konfigurasi VPN diunduh', 'change'],
        'vpn_renew_requested' => ['Perpanjangan VPN diajukan', 'change'],
    ];

    /**
     * @param  list<array<string, mixed>>  $keycloakEvents  Keycloak event representations (time in ms)
     * @param  list<array{event: string, at: int, ip: ?string}>  $portalEvents  at = unix seconds
     * @param  array<string, string>  $appNames
     * @return list<array<string, mixed>>
     */
    public static function merge(array $keycloakEvents, array $portalEvents, array $appNames, int $limit = 100): array
    {
        $items = [];

        foreach ($keycloakEvents as $e) {
            $type = $e['type'] ?? null;

            if (! isset(self::KEYCLOAK_EVENTS[$type]) || ! is_numeric($e['time'] ?? null)) {
                continue;
            }

            [$label, $kind] = self::KEYCLOAK_EVENTS[$type];
            $client = is_string($e['clientId'] ?? null) ? $e['clientId'] : null;

            $items[] = [
                'at' => intdiv((int) $e['time'], 1000),
                'label' => $label,
                'kind' => $kind,
                'app' => $client !== null ? ($appNames[$client] ?? $client) : null,
                'ip' => is_string($e['ipAddress'] ?? null) ? $e['ipAddress'] : null,
                // Keycloak error code, e.g. invalid_user_credentials; useful
                // to tell a wrong password from a wrong OTP.
                'detail' => $kind === 'failure' && is_string($e['error'] ?? null) ? $e['error'] : null,
            ];
        }

        foreach ($portalEvents as $e) {
            if (! isset(self::PORTAL_EVENTS[$e['event']])) {
                continue;
            }

            [$label, $kind] = self::PORTAL_EVENTS[$e['event']];

            $items[] = [
                'at' => $e['at'],
                'label' => $label,
                'kind' => $kind,
                'app' => 'Akun ASN',
                'ip' => $e['ip'],
                'detail' => null,
            ];
        }

        usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);

        return array_map(function (array $item) {
            $item['at'] = gmdate('Y-m-d\TH:i:s\Z', $item['at']);

            return $item;
        }, array_slice($items, 0, $limit));
    }
}
