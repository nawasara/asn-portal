<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\MailboxDirectory;
use Nawasara\AsnPortal\Services\SiteDirectory;

/**
 * GET /account/storage — the "Penyimpanan" page: how full each mailbox and
 * hosting account the user is responsible for is.
 *
 * Built from the same directories as /mailboxes and /sites, so the numbers
 * on the three pages never disagree. Personal and agency are kept apart:
 * a full agency mailbox is not the user's own quota problem.
 */
class StorageController extends Controller
{
    public function __invoke(Request $request, MailboxDirectory $mailboxes, SiteDirectory $sites): JsonResponse
    {
        $sub = (string) $request->attributes->get('staff_sub');
        $email = $request->attributes->get('staff_claims')['email'] ?? null;

        $mail = $mailboxes->forUser($sub, is_string($email) ? $email : null)
            ->map(fn (array $m) => [
                'address' => $m['address'],
                'kind' => $m['kind'],
                'used_mb' => $m['used_mb'],
                'quota_mb' => $m['quota_mb'],
                'used_percent' => self::percent($m['used_mb'], $m['quota_mb']),
            ]);

        $hosting = $sites->forUser($sub)
            ->filter(fn (array $s) => $s['hosting'] !== null)
            ->map(fn (array $s) => [
                'host' => $s['host'],
                'used_mb' => $s['hosting']['disk_used_mb'],
                'quota_mb' => $s['hosting']['disk_limit_mb'],
                'used_percent' => self::percent($s['hosting']['disk_used_mb'], $s['hosting']['disk_limit_mb']),
            ]);

        return response()->json(['data' => [
            'personal' => $mail->firstWhere('kind', 'personal'),
            'agency_mailboxes' => $mail->where('kind', 'agency')->values(),
            'hosting' => $hosting->values(),
        ]]);
    }

    /** Null when there is no quota (unlimited) rather than a misleading 0%. */
    public static function percent(?float $used, ?int $quota): ?int
    {
        if (! $quota) {
            return null;
        }

        return (int) min(100, round(($used ?? 0) / $quota * 100));
    }
}
