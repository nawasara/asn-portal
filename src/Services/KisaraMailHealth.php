<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Alerting for Kisara Mail (its FSD NFR-07): every 5 minutes, read Kisara
 * Mail's failure counters and raise a Telegram alert through
 * nawasara/alerting when more than 5 % of mail-server logins failed.
 *
 * Pull, not push: Kisara Mail needs no Nawasara credential to report, and
 * the counters endpoint is reachable only from inside (LXC 143 port 8080 is
 * firewalled to LXC 140; requests through the public hostname get 404).
 *
 * Off when `kisara_mail.metrics_url` is empty — every environment except
 * production, which would otherwise alert about a server it cannot reach.
 */
class KisaraMailHealth
{
    public const RULE_IMAP = 'kisara-mail.imap-failing';

    public const RULE_UNREACHABLE = 'kisara-mail.unreachable';

    /** Target ids stay fixed: an alert is about WHAT fails, not about one check run. */
    public const TARGET_TYPE = 'KisaraMail';

    public function check(): void
    {
        $url = (string) config('nawasara-asn-portal.kisara_mail.metrics_url', '');
        if ($url === '' || ! class_exists(\Nawasara\Alerting\Facades\Alerter::class)) {
            return;
        }

        try {
            $res = Http::timeout(5)->acceptJson()->get($url);
            $data = $res->successful() ? $res->json() : null;
        } catch (\Throwable $e) {
            $data = null;
        }

        if (! is_array($data) || ! isset($data['imap']['attempts'], $data['imap']['failures'])) {
            Log::warning('asn-portal: Kisara Mail metrics unreachable', ['url' => $url]);
            \Nawasara\Alerting\Facades\Alerter::fire(self::RULE_UNREACHABLE, self::TARGET_TYPE, 'service', ['url' => $url]);

            return;
        }
        \Nawasara\Alerting\Facades\Alerter::resolve(self::RULE_UNREACHABLE, self::TARGET_TYPE, 'service');

        $attempts = (int) $data['imap']['attempts'];
        $failures = (int) $data['imap']['failures'];
        $verdict = self::assess($attempts, $failures, (float) config('nawasara-asn-portal.kisara_mail.imap_failure_ratio', 0.05), (int) config('nawasara-asn-portal.kisara_mail.min_attempts', 10));

        if ($verdict === 'failing') {
            \Nawasara\Alerting\Facades\Alerter::fire(self::RULE_IMAP, self::TARGET_TYPE, 'imap', [
                'failures' => $failures,
                'attempts' => $attempts,
                'pct' => (int) round($failures / max(1, $attempts) * 100),
                'minutes' => (int) ($data['windowMinutes'] ?? 5),
            ]);
        } elseif ($verdict === 'ok') {
            \Nawasara\Alerting\Facades\Alerter::resolve(self::RULE_IMAP, self::TARGET_TYPE, 'imap');
        }
        // 'quiet': too few logins to judge; leave the alert as it is.
    }

    /**
     * Pure decision, testable without HTTP. Below `minAttempts` logins the
     * ratio means nothing (1 failure of 3 is 33 %), so no verdict is given.
     *
     * @return 'failing'|'ok'|'quiet'
     */
    public static function assess(int $attempts, int $failures, float $ratio, int $minAttempts): string
    {
        if ($attempts < $minAttempts) {
            return 'quiet';
        }

        return $failures / $attempts > $ratio ? 'failing' : 'ok';
    }
}
