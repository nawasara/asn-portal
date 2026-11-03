<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Turn the Keycloak credential list of one user into what the portal shows,
 * and decide which credentials a user may remove.
 *
 * Pure, so the rules are tested without Keycloak.
 */
final class CredentialSummary
{
    public const TYPE_PASSWORD = 'password';

    public const TYPE_OTP = 'otp';

    public const TYPE_PASSKEY = 'webauthn-passwordless';

    public const TYPE_SECURITY_KEY = 'webauthn';

    public const TYPE_BACKUP_CODES = 'recovery-authn-codes';

    /** Types a user may see and manage from the portal. */
    public const MANAGEABLE = [self::TYPE_OTP, self::TYPE_PASSKEY, self::TYPE_SECURITY_KEY, self::TYPE_BACKUP_CODES];

    /**
     * Allow-listed view of each manageable credential. credentialData and
     * secretData never leave. For passkeys, only a provider NAME derived
     * from the AAGUID goes out ("iCloud Keychain", "Windows Hello"), so the
     * user can tell a synced passkey from one bound to a single device; the
     * raw AAGUID and credential id stay behind.
     *
     * @param  list<array<string, mixed>>  $credentials
     * @return list<array<string, mixed>>
     */
    public static function present(array $credentials): array
    {
        $out = [];

        foreach ($credentials as $c) {
            $type = $c['type'] ?? null;

            if (! in_array($type, self::MANAGEABLE, true) || ! is_string($c['id'] ?? null)) {
                continue;
            }

            $out[] = [
                'id' => $c['id'],
                'type' => match ($type) {
                    self::TYPE_OTP => 'totp',
                    self::TYPE_PASSKEY => 'passkey',
                    self::TYPE_SECURITY_KEY => 'security_key',
                    self::TYPE_BACKUP_CODES => 'backup_codes',
                },
                'label' => is_string($c['userLabel'] ?? null) && $c['userLabel'] !== '' ? $c['userLabel'] : null,
                'created_at' => self::isoMillis($c['createdDate'] ?? null),
                ...self::provider($type, $c['credentialData'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Known passkey providers by AAGUID → [name, synced]. Synced = the
     * passkey follows the user's iCloud/Google/password-manager account to
     * other devices; not synced = it lives on that one device. An AAGUID not
     * listed (or the all-zero one many authenticators send) gives no name
     * rather than a guess.
     */
    public const PASSKEY_PROVIDERS = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => ['iCloud Keychain', true],
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => ['iCloud Keychain', true],
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => ['Google Password Manager', true],
        '53414d53-554e-4700-0000-000000000000' => ['Samsung Pass', true],
        'bada5566-a7aa-401f-bd96-45619a55120d' => ['1Password', true],
        'd548826e-79b4-db40-a3d8-11116f7e8349' => ['Bitwarden', true],
        '531126d6-e717-415c-9320-3d9aa6981239' => ['Dashlane', true],
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => ['Windows Hello', false],
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => ['Windows Hello', false],
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => ['Windows Hello', false],
        'adce0002-35bc-c60a-648b-0b25f1f05503' => ['Chrome di Mac', false],
    ];

    /** @return array{provider: ?string, synced: ?bool} */
    private static function provider(string $type, mixed $credentialData): array
    {
        if (! in_array($type, [self::TYPE_PASSKEY, self::TYPE_SECURITY_KEY], true) || ! is_string($credentialData)) {
            return ['provider' => null, 'synced' => null];
        }

        $aaguid = strtolower((string) (json_decode($credentialData, true)['aaguid'] ?? ''));
        [$name, $synced] = self::PASSKEY_PROVIDERS[$aaguid] ?? [null, null];

        return ['provider' => $name, 'synced' => $synced];
    }

    /**
     * Counts per kind plus the security suggestions the checkup shows.
     *
     * @param  list<array<string, mixed>>  $credentials
     * @return array{passkeys: int, authenticators: int, backup_codes: bool, has_password: bool, suggestions: list<string>}
     */
    public static function summarize(array $credentials, bool $hasRecoveryContact, bool $backupCodesSupported = true): array
    {
        $count = fn (string $type) => count(array_filter($credentials, fn ($c) => ($c['type'] ?? null) === $type));

        $summary = [
            'passkeys' => $count(self::TYPE_PASSKEY),
            'authenticators' => $count(self::TYPE_OTP),
            'backup_codes' => $count(self::TYPE_BACKUP_CODES) > 0,
            'has_password' => $count(self::TYPE_PASSWORD) > 0,
            'backup_codes_supported' => $backupCodesSupported,
            'suggestions' => [],
        ];

        if ($summary['passkeys'] === 0) {
            $summary['suggestions'][] = 'add_passkey';
        }

        if ($summary['authenticators'] === 0) {
            $summary['suggestions'][] = 'add_authenticator';
        }

        if ($backupCodesSupported && ! $summary['backup_codes']) {
            $summary['suggestions'][] = 'create_backup_codes';
        }

        if (! $hasRecoveryContact) {
            $summary['suggestions'][] = 'add_recovery_contact';
        }

        return $summary;
    }

    /**
     * Why a credential may NOT be removed, or null when it may.
     *
     * - Unknown id, or a type the portal does not manage (the password
     *   belongs to SimasHebat): refused.
     * - The LAST authenticator app: two-step verification is mandatory for
     *   every ASN account, so removing it would lock the user out at the
     *   next sign-in. They must add another first, or replace it through
     *   the authenticator action (which swaps it in one step).
     *
     * @param  list<array<string, mixed>>  $credentials
     */
    public static function removalBlockedReason(array $credentials, string $credentialId): ?string
    {
        $target = null;

        foreach ($credentials as $c) {
            if (($c['id'] ?? null) === $credentialId) {
                $target = $c;
                break;
            }
        }

        if ($target === null || ! in_array($target['type'] ?? null, self::MANAGEABLE, true)) {
            return 'not_found';
        }

        if ($target['type'] === self::TYPE_OTP) {
            $otpCount = count(array_filter($credentials, fn ($c) => ($c['type'] ?? null) === self::TYPE_OTP));

            if ($otpCount <= 1) {
                return 'last_authenticator';
            }
        }

        return null;
    }

    /** Whether a credential id belongs to this user's manageable list. */
    public static function owns(array $credentials, string $credentialId): bool
    {
        foreach ($credentials as $c) {
            if (($c['id'] ?? null) === $credentialId && in_array($c['type'] ?? null, self::MANAGEABLE, true)) {
                return true;
            }
        }

        return false;
    }

    private static function isoMillis(mixed $value): ?string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $value, 1000));
    }
}
