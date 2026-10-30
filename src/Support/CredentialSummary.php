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
     * secretData never leave: the passkey's credential id and authenticator
     * model are not the portal's business.
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
            ];
        }

        return $out;
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
