<?php

namespace Nawasara\AsnPortal\Support;

/**
 * One spelling for a site's host, so a registry asset, a Kuma monitor URL,
 * a cPanel domain and a secscan target can be matched without a link table.
 *
 *   "https://WWW.Kominfo.Ponorogo.go.id:443/berita?x=1" → "kominfo.ponorogo.go.id"
 *
 * `www.` is dropped because monitors and scanners use either form for the
 * same site. Anything that is not a host name (an IP, an email, empty)
 * gives null, so it never matches another asset by accident.
 */
final class SiteHost
{
    public static function normalize(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '' || str_contains($value, '@')) {
            return null;
        }

        if (! str_contains($value, '://')) {
            $value = 'http://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
            return null;
        }

        return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : null;
    }
}
