<?php

namespace Nawasara\AsnPortal\Support;

/**
 * What to do with an email that SIMAS Hebat says belongs to a civil servant.
 *
 * Kept free of Keycloak and HTTP so the rules can be tested on their own.
 */
final class EmailChange
{
    public const UNCHANGED = 'unchanged';

    public const CONFLICT = 'conflict';

    public const UPDATE = 'update';

    /**
     * Trimmed and lower-cased, or null when it is not a usable address.
     * Keycloak itself stores emails lower-cased; comparing any other way
     * would report a change that is not one.
     */
    public static function normalize(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * @param  string|null  $current  the account's email now
     * @param  string  $requested  normalized new email
     * @param  string|null  $ownerId  Keycloak id of the account that already uses $requested, if any
     * @param  string  $userId  Keycloak id of the account being changed
     */
    public static function decide(?string $current, string $requested, ?string $ownerId, string $userId): string
    {
        if ($current !== null && mb_strtolower($current) === $requested) {
            return self::UNCHANGED;
        }

        // The realm does not allow two accounts on one email. Moving it would
        // also hand that account's reset links to someone else, so it is never
        // done here; a person resolves it.
        if ($ownerId !== null && $ownerId !== $userId) {
            return self::CONFLICT;
        }

        return self::UPDATE;
    }
}
