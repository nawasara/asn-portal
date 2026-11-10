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
     * Like record(), but a failed write throws. For audit rows that are a
     * condition of the action itself: opening a shared mailbox must be
     * visible to its other holders, so no row means no opening (FSD FR-05).
     *
     * @param  array<string, mixed>  $extra
     */
    public function recordOrFail(string $event, string $sub, ?string $ip, array $extra = []): void
    {
        activity(self::LOG_NAME)
            ->event($event)
            ->withProperties(['sub' => $sub, 'ip' => $ip] + $extra)
            ->log($event);
    }

    /** Events that record someone opening a mailbox; kept 1 year (FSD FR-05). */
    public const MAILBOX_OPEN_EVENTS = ['webmail_opened', 'mailbox_opened'];

    public const MAILBOX_OPEN_RETENTION_DAYS = 365;

    /** What Kisara Mail did with a mailbox's OpenPGP key; type in properties (Support\PgpEvent). */
    public const MAILBOX_PGP_EVENT = 'mailbox_pgp_event';

    /** Everything a shared mailbox's holders see in its access history; same retention. */
    public const MAILBOX_ACCESS_EVENTS = [...self::MAILBOX_OPEN_EVENTS, self::MAILBOX_PGP_EVENT];

    /** Delete mailbox access rows (openings, key events) older than the retention. Returns how many. */
    public function pruneMailboxOpens(): int
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->whereIn('event', self::MAILBOX_ACCESS_EVENTS)
            ->where('created_at', '<', now()->subDays(self::MAILBOX_OPEN_RETENTION_DAYS))
            ->delete();
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
