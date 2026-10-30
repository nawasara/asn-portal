<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Indonesian mobile numbers as typed by people, and how they are shown back.
 *
 * Ported from the old portal (UpdateWhatsappNumber\Section\Form): the same
 * normalisation, so numbers already stored in Keycloak keep their format.
 */
final class PhoneNumber
{
    /**
     * "0857-3667-6648", "+62 857...", "62857..." → "62857...", or null when it
     * is not a plausible Indonesian mobile number (08 + 8 to 12 digits).
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        if (preg_match('/^08\d{8,12}$/', $digits) !== 1) {
            return null;
        }

        return '62'.substr($digits, 1);
    }

    /** "6281234567890" → "0812 •••• 7890". Never shows the middle digits. */
    public static function mask(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $stored) ?? '';

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) < 8) {
            return '••••';
        }

        return substr($digits, 0, 4).' •••• '.substr($digits, -4);
    }

    /** "dyah.lestari@ponorogo.go.id" → "dy•••@ponorogo.go.id". */
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, min(2, mb_strlen($local))).'•••@'.$domain;
    }
}
