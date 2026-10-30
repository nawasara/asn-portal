<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Keycloak user sessions → what "Perangkat" and "Aplikasi terhubung" show.
 *
 * Keycloak returns for each session: id, ipAddress, start, lastAccess (both
 * in milliseconds) and `clients` as {clientUuid: clientId}. The client ids
 * are mapped to readable names through the app catalog.
 */
final class SessionPresenter
{
    /**
     * @param  list<array<string, mixed>>  $sessions
     * @param  array<string, string>  $appNames  keycloak client id → name
     * @param  string|null  $currentSessionId  `sid` claim of the caller's token
     * @return list<array<string, mixed>>
     */
    public static function present(array $sessions, array $appNames, ?string $currentSessionId): array
    {
        $out = [];

        foreach ($sessions as $s) {
            if (! is_string($s['id'] ?? null)) {
                continue;
            }

            $clientIds = array_values(array_filter((array) ($s['clients'] ?? []), 'is_string'));

            $out[] = [
                'id' => $s['id'],
                'current' => $currentSessionId !== null && $s['id'] === $currentSessionId,
                'ip' => is_string($s['ipAddress'] ?? null) ? $s['ipAddress'] : null,
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

    private static function isoMillis(mixed $value): ?string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $value, 1000));
    }
}
