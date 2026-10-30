<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\SecurityNotifier;
use Nawasara\AsnPortal\Support\ClientIp;

/**
 * The caller's own VPN accounts (nawasara/opnsense), self-service.
 *
 * Accounts belong to a users row; the owner is found through
 * users.keycloak_id = sub. Admins provision VPN owners from the Keycloak
 * directory, so every managed account has that link.
 *
 * The .conf holds the account's private key. It is returned only on a fresh
 * login, every download is audited and mailed to the owner, and the portal
 * renders the QR code from the same response; Nawasara does not hand out a
 * second copy of the key as an image.
 */
class VpnController extends Controller
{
    public function __construct(
        protected PortalAudit $audit,
        protected SecurityNotifier $notifier,
    ) {}

    /** GET /account/vpn */
    public function index(Request $request): JsonResponse
    {
        if (! $this->available()) {
            return response()->json(['data' => []]);
        }

        $accounts = $this->owned($request)
            ->where('status', '!=', \Nawasara\Opnsense\Models\OpnsenseVpnAccount::STATUS_REVOKED)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $accounts->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'tunnel_address' => $a->tunnel_address,
            'status' => $a->isExpired() ? 'expired' : $a->status,
            'expires_at' => $a->expires_at?->toIso8601String(),
            'days_left' => $a->daysUntilExpiry(),
            'last_handshake_at' => $a->last_handshake_at?->toIso8601String(),
            'renew_requested_at' => $a->renew_requested_at?->toIso8601String(),
            'config_available' => ! empty($a->server_uuid) && $a->getRawOriginal('private_key') !== null,
        ])->values()]);
    }

    /** POST /account/vpn/{id}/config — fresh login */
    public function config(Request $request, int $id): JsonResponse
    {
        $account = $this->available() ? $this->owned($request)->with('appliance')->find($id) : null;

        if ($account === null || $account->isRevoked()) {
            return $this->notFound();
        }

        if ($account->isExpired()) {
            return response()->json(['error' => [
                'code' => 'vpn_expired',
                'message' => 'Akun VPN ini sudah kedaluwarsa. Ajukan perpanjangan dulu.',
            ]], 409);
        }

        try {
            $conf = app(\Nawasara\Opnsense\Services\WireguardConfigBuilder::class)->build($account);
        } catch (\Throwable) {
            $conf = null;
        }

        if ($conf === null) {
            return response()->json(['error' => [
                'code' => 'vpn_config_unavailable',
                'message' => 'Konfigurasi VPN ini tidak dapat dibuat ulang. Hubungi RAKACA.',
            ]], 503);
        }

        $this->audit->record('vpn_config_downloaded', $this->sub($request), ClientIp::from($request), ['vpn' => $account->name]);
        $this->notifier->alert($this->accountEmail($request), 'Konfigurasi VPN diunduh', [
            'Konfigurasi VPN '.$account->name.' baru saja diunduh dari Akun ASN.',
            'Kalau ini bukan Anda, segera hubungi RAKACA agar akun VPN dicabut.',
        ]);

        return response()->json(['data' => [
            'filename' => preg_replace('/[^A-Za-z0-9._-]/', '-', $account->name).'.conf',
            'config' => $conf,
        ]], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * POST /account/vpn/{id}/renew-request — renewal itself stays with an
     * admin (open decision I4); this only flags the account in the panel.
     */
    public function requestRenewal(Request $request, int $id): JsonResponse
    {
        $account = $this->available() ? $this->owned($request)->find($id) : null;

        if ($account === null || $account->isRevoked()) {
            return $this->notFound();
        }

        if ($account->renew_requested_at === null) {
            $account->forceFill(['renew_requested_at' => now()])->save();
            $this->audit->record('vpn_renew_requested', $this->sub($request), ClientIp::from($request), ['vpn' => $account->name]);
        }

        return response()->json(['data' => ['renew_requested_at' => $account->renew_requested_at->toIso8601String()]]);
    }

    private function owned(Request $request): Builder
    {
        $sub = $this->sub($request);

        return \Nawasara\Opnsense\Models\OpnsenseVpnAccount::query()
            ->whereHas('user', fn (Builder $q) => $q->where('keycloak_id', $sub));
    }

    private function available(): bool
    {
        return class_exists(\Nawasara\Opnsense\Models\OpnsenseVpnAccount::class);
    }

    private function accountEmail(Request $request): ?string
    {
        $email = $request->attributes->get('staff_claims')['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    private function sub(Request $request): string
    {
        return (string) $request->attributes->get('staff_sub');
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'not_found', 'message' => 'Akun VPN tidak ditemukan.']], 404);
    }
}
