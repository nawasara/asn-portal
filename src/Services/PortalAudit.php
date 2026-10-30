<?php

namespace Nawasara\AsnPortal\Services;

use Spatie\Activitylog\Models\Activity;

/**
 * Audit trail of changes made through the account portal, in the shared
 * Spatie activity log under log name `asn-portal`.
 *
 * Keyed on the Keycloak `sub`, not a causer: most civil servants have no
 * local users row. The same rows feed the user's own "Aktivitas" screen.
 */
class PortalAudit
{
    public const LOG_NAME = 'asn-portal';

    /** @param  array<string, mixed>  $extra */
    public function record(string $event, string $sub, ?string $ip, array $extra = []): void
    {
        try {
            activity(self::LOG_NAME)
                ->event($event)
                ->withProperties(['sub' => $sub, 'ip' => $ip] + $extra)
                ->log($event);
        } catch (\Throwable) {
            // Failing to write the audit row must not undo or fail the change
            // itself; the change already happened in Keycloak.
        }
    }

    /**
     * @return list<array{event: string, at: int, ip: ?string}>
     */
    public function eventsFor(string $sub, int $days): array
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('properties->sub', $sub)
            ->where('created_at', '>=', now()->subDays($days))
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Activity $a) => [
                'event' => (string) $a->event,
                'at' => $a->created_at->getTimestamp(),
                'ip' => $a->getExtraProperty('ip'),
            ])
            ->all();
    }
}
