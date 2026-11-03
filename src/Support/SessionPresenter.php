<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Keycloak user sessions → what "Perangkat" and "Aplikasi terhubung" show.
 *
 * Keycloak returns for each session: id, ipAddress, start, lastAccess (both
 * in milliseconds) and `clients` as {clientUuid: clientId}. The client ids
 * are mapped to readable names through the app catalog.
 *
 * The admin API carries no browser or OS. Those come from the account API's
 * device list (`/account/sessions/devices`, called with the user's own
 * token), which groups the same sessions by device; they are joined here by
 * session id. When that list is unavailable the fields are null and the
 * portal falls back to the IP address.
 */
final class SessionPresenter
{
    /**
     * @param  list<array<string, mixed>>  $sessions
     * @param  array<string, string>  $appNames  keycloak client id → name
     * @param  string|null  $currentSessionId  `sid` claim of the caller's token
     * @param  list<array<string, mixed>>  $devices  account API device list
     * @param  array<string, array{label: string, network: ?string, unusual: bool}>  $places  IP => place (IpLocator)
     * @return list<array<string, mixed>>
     */
    public static function present(array $sessions, array $appNames, ?string $currentSessionId, array $devices = [], array $places = []): array
    {
        $out = [];
        $deviceOf = self::devicesBySession($devices);

        foreach ($sessions as $s) {
            if (! is_string($s['id'] ?? null)) {
                continue;
            }

            $clientIds = array_values(array_filter((array) ($s['clients'] ?? []), 'is_string'));
            $device = $deviceOf[$s['id']] ?? null;
            $ip = is_string($s['ipAddress'] ?? null) ? $s['ipAddress'] : null;
            $place = $ip !== null ? ($places[$ip] ?? null) : null;

            $out[] = [
                'id' => $s['id'],
                'current' => $currentSessionId !== null && $s['id'] === $currentSessionId,
                'ip' => $ip,
                'location' => $place['label'] ?? null,
                'network' => $place['network'] ?? null,
                'unusual_location' => $place['unusual'] ?? false,
                'browser' => $device['browser'] ?? null,
                'os' => $device['os'] ?? null,
                'device' => $device['device'] ?? null,
                'mobile' => $device['mobile'] ?? null,
                'started_at' => self::isoMillis($s['start'] ?? null),
                'last_active_at' => self::isoMillis($s['lastAccess'] ?? null),
                'apps' => array_map(fn (string $clientId) => [
                    'client_id' => $clientId,
                    'name' => $appNames[$clientId] ?? $clientId,
                ], $clientIds),
            ];
        }

        // Current device first, then most recently active.
        usort($out, fn ($a, $b) => [$b['current'], $b['last_active_at']] <=> [$a['current'], $a['last_active_at']]);

        return $out;
    }

    /** @param  list<array<string, mixed>>  $sessions */
    public static function owns(array $sessions, string $sessionId): bool
    {
        foreach ($sessions as $s) {
            if (($s['id'] ?? null) === $sessionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * session id → browser/OS/device. Keycloak writes "Unknown" and "Other"
     * when it cannot parse the user agent; those become null so the portal
     * shows nothing rather than a placeholder word.
     *
     * @param  list<array<string, mixed>>  $devices
     * @return array<string, array<string, mixed>>
     */
    private static function devicesBySession(array $devices): array
    {
        $known = fn (mixed $v): ?string => is_string($v) && $v !== '' && ! in_array($v, ['Unknown', 'Other'], true) ? $v : null;
        $map = [];

        foreach ($devices as $d) {
            $os = $known($d['os'] ?? null);
            $version = $known($d['osVersion'] ?? null);

            foreach ((array) ($d['sessions'] ?? []) as $s) {
                if (! is_string($s['id'] ?? null)) {
                    continue;
                }

                // "Chrome/130.0.0" → "Chrome": the version adds nothing for
                // recognising your own device.
                $browser = $known($s['browser'] ?? $d['browser'] ?? null);

                $map[$s['id']] = [
                    'browser' => $browser !== null ? trim(explode('/', $browser)[0]) : null,
                    'os' => $os !== null ? trim($os.' '.($version ?? '')) : null,
                    'device' => $known($d['device'] ?? null),
                    'mobile' => (bool) ($d['mobile'] ?? false),
                ];
            }
        }

        return $map;
    }

    private static function isoMillis(mixed $value): ?string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $value, 1000));
    }
}
