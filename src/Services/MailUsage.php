<?php

namespace Nawasara\AsnPortal\Services;

use Carbon\CarbonImmutable;

/**
 * Weekly mailbox openings per channel (Kisara Mail FSD FR-25): the number
 * the project owner uses to decide when Kisara Mail can replace Roundcube.
 *
 * Source: the portal audit trail, which already records both channels:
 *   webmail_opened                       → Roundcube via Akun Kisara
 *   mailbox_opened, channel kisara-mail  → Kisara Mail
 * Both are kept 365 days (PortalAudit::pruneMailboxOpens), so at most a
 * year of weeks can be shown.
 *
 * Weeks run Monday–Sunday in WIB, the calendar staff actually read.
 */
class MailUsage
{
    public const TZ = 'Asia/Jakarta';

    /** @return list<array{week: string, roundcube_opens: int, roundcube_users: int, kisara_opens: int, kisara_users: int}> */
    public function weekly(int $weeks = 12): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $from = self::weekStart($now)->subWeeks($weeks - 1);

        $rows = \Spatie\Activitylog\Models\Activity::query()
            ->where('log_name', PortalAudit::LOG_NAME)
            ->whereIn('event', PortalAudit::MAILBOX_OPEN_EVENTS)
            ->where('created_at', '>=', $from->utc())
            ->get(['event', 'properties', 'created_at'])
            ->map(fn ($a) => [
                'at' => CarbonImmutable::parse($a->created_at),
                'event' => (string) $a->event,
                'channel' => $a->getExtraProperty('channel'),
                'sub' => $a->getExtraProperty('sub'),
            ]);

        return self::aggregate($rows, $now, $weeks);
    }

    /**
     * Pure part, testable without a database. Newest week first; weeks with
     * no openings are included as zeros so a quiet week is visible as such.
     *
     * @param  iterable<array{at: CarbonImmutable, event: string, channel: mixed, sub: mixed}>  $rows
     * @return list<array{week: string, roundcube_opens: int, roundcube_users: int, kisara_opens: int, kisara_users: int}>
     */
    public static function aggregate(iterable $rows, CarbonImmutable $now, int $weeks): array
    {
        $buckets = [];
        $start = self::weekStart($now->setTimezone(self::TZ));
        for ($i = 0; $i < $weeks; $i++) {
            $buckets[$start->subWeeks($i)->toDateString()] = ['roundcube' => [0, []], 'kisara' => [0, []]];
        }

        foreach ($rows as $r) {
            $channel = match (true) {
                $r['event'] === 'webmail_opened' => 'roundcube',
                $r['event'] === 'mailbox_opened' && $r['channel'] === 'kisara-mail' => 'kisara',
                default => null,
            };
            $week = self::weekStart($r['at']->setTimezone(self::TZ))->toDateString();
            if ($channel === null || ! isset($buckets[$week])) {
                continue;
            }
            $buckets[$week][$channel][0]++;
            if (is_string($r['sub']) && $r['sub'] !== '') {
                $buckets[$week][$channel][1][$r['sub']] = true;
            }
        }

        $out = [];
        foreach ($buckets as $week => $b) {
            $out[] = [
                'week' => $week,
                'roundcube_opens' => $b['roundcube'][0],
                'roundcube_users' => count($b['roundcube'][1]),
                'kisara_opens' => $b['kisara'][0],
                'kisara_users' => count($b['kisara'][1]),
            ];
        }

        return $out;
    }

    private static function weekStart(CarbonImmutable $t): CarbonImmutable
    {
        return $t->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }
}
