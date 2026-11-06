<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\SiteDirectory;
use Nawasara\AsnPortal\Support\ClientIp;

/**
 * GET /account/sites — the agency sites this civil servant is a PIC of.
 *
 * Findings are returned as counts only. Their evidence (matched spam
 * keywords, defaced page content, database names) stays in the panel,
 * where the security team works on them.
 */
class SiteController extends Controller
{
    public function __invoke(Request $request, SiteDirectory $sites): JsonResponse
    {
        return response()->json(['data' => $sites->forUser($this->sub($request))]);
    }

    /**
     * POST /account/sites/{host}/cpanel — one-time cPanel login URL.
     *
     * Only for a host whose cPanel account (registry asset linked to WHM) the
     * caller is an active PIC of. The URL comes from WHM create_user_session,
     * is single-use and expires in minutes; no cPanel password is ever shown,
     * so access ends with the PIC assignment. Behind recent-auth: a cPanel
     * session controls the site's files, databases and mail.
     */
    public function cpanel(Request $request, string $host, SiteDirectory $sites, PortalAudit $audit): JsonResponse
    {
        if (! class_exists(\Nawasara\Whm\Services\WebmailSessionService::class)) {
            return $this->error('unavailable', 'Login cPanel belum tersedia.', 503);
        }

        $sub = $this->sub($request);
        $asset = $sites->cpanelAccountFor($sub, $host);

        // 404, bukan 403: yang bukan PIC tidak perlu tahu akun itu ada.
        if (! $asset) {
            return $this->error('not_found', 'Situs tidak ditemukan.', 404);
        }

        $account = \Nawasara\Whm\Models\WhmAccount::where('username', $asset->external_id)->first();

        if (! $account) {
            return $this->error('not_found', 'Akun cPanel situs ini tidak ditemukan di WHM.', 404);
        }

        if ($account->suspended) {
            return $this->error('suspended', 'Akun hosting ini sedang ditangguhkan. Hubungi tim hosting Diskominfo.', 422);
        }

        $ip = ClientIp::from($request);
        $userId = config('auth.providers.users.model')::query()->where('keycloak_id', $sub)->value('id');

        try {
            $session = app(\Nawasara\Whm\Services\WebmailSessionService::class)
                ->createSession($account->username, 'cpaneld', $account->instance);
        } catch (\Throwable $e) {
            report($e);
            $this->recordCpanelSession($userId, $account, $ip, $request, 'failed', $e->getMessage());

            return $this->error('cpanel_unavailable', 'cPanel sedang tidak dapat dibuka. Coba lagi beberapa saat lagi.', 422);
        }

        // Dua jejak: riwayat login cPanel di panel WHM (bersama login-as admin)
        // dan aktivitas akun PIC sendiri di Akun Kisara.
        $this->recordCpanelSession($userId, $account, $ip, $request, 'success', null);
        $audit->record('cpanel_opened', $sub, $ip, ['host' => $account->domain]);

        return response()->json(['data' => ['url' => $session['url']]]);
    }

    /** Satu tabel untuk semua login cPanel tanpa sandi, admin maupun PIC. */
    private function recordCpanelSession(?int $userId, object $account, string $ip, Request $request, string $status, ?string $error): void
    {
        if (! class_exists(\Nawasara\Whm\Models\CpanelSession::class)) {
            return;
        }

        try {
            \Nawasara\Whm\Models\CpanelSession::create([
                'acted_by_user_id' => $userId,
                'cpanel_user' => $account->username,
                'domain' => $account->domain,
                'instance' => $account->instance,
                'reason' => 'PIC lewat Akun Kisara',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'status' => $status,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function sub(Request $request): string
    {
        return (string) $request->attributes->get('staff_sub');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
