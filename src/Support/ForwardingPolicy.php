<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Where an official mailbox may forward to.
 *
 * Only to addresses under the allowed domains (default ponorogo.go.id and
 * its subdomains). A forwarder to a private Gmail is invisible to the
 * mailbox's other PICs and keeps copying official mail out after the person
 * has moved on; that is exactly the access the password-less agency mailbox
 * design is meant to end cleanly.
 */
final class ForwardingPolicy
{
    /** @param  list<string>  $allowedDomains */
    public static function allows(string $destination, array $allowedDomains): bool
    {
        $destination = strtolower(trim($destination));

        if (filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = substr($destination, strrpos($destination, '@') + 1);

        foreach ($allowedDomains as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed !== '' && ($domain === $allowed || str_ends_with($domain, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strength rule for a personal mailbox password set from the portal.
     * Returns an Indonesian message, or null when acceptable.
     */
    public static function passwordProblem(string $password, string $address): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'Kata sandi minimal 12 karakter.';
        }

        $local = strtolower(strstr($address, '@', true) ?: $address);

        if ($local !== '' && str_contains(strtolower($password), $local)) {
            return 'Kata sandi tidak boleh memuat nama alamat email.';
        }

        if (count(array_unique(mb_str_split($password))) < 5) {
            return 'Kata sandi terlalu mudah ditebak.';
        }

        return null;
    }
}
