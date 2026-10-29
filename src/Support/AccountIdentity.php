<?php

namespace Nawasara\AsnPortal\Support;

/**
 * The signed-in civil servant as the portal sees them, built from the
 * verified token claims.
 *
 * An allow-list on purpose (AGENTS.md §10a): only the fields named here
 * leave the API. Passing the claims through would hand the portal whatever
 * a future Keycloak mapper adds to the token, including attributes nobody
 * meant to expose.
 *
 * Employment data (position, unit) comes from SimasHebat and is added in a
 * later stage; the token does not carry it.
 */
final class AccountIdentity
{
    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    public static function fromClaims(array $claims): array
    {
        $username = self::string($claims['preferred_username'] ?? null);

        return [
            'sub' => self::string($claims['sub'] ?? null),
            'name' => self::string($claims['name'] ?? null),
            // The employee realm signs in with the NIP as username. Exposed as
            // `nip` only when it looks like one (18 digits), so a service
            // account or a test user is never shown as if it had a NIP.
            'nip' => $username !== null && preg_match('/^\d{18}$/', $username) === 1 ? $username : null,
            'username' => $username,
            'email' => self::string($claims['email'] ?? null),
            'email_verified' => (bool) ($claims['email_verified'] ?? false),
            'signed_in_at' => self::isoTime($claims['auth_time'] ?? null),
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function isoTime(mixed $value): ?string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', (int) $value);
    }
}
