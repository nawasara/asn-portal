<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\KeycloakDirectory;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\SecurityNotifier;
use Nawasara\AsnPortal\Support\ActivityPresenter;
use Nawasara\AsnPortal\Support\ClientIp;
use Nawasara\AsnPortal\Support\CredentialSummary;
use Nawasara\AsnPortal\Support\PhoneNumber;
use Nawasara\AsnPortal\Support\SessionPresenter;

/**
 * Profile, sign-in methods, devices and activity of the signed-in civil
 * servant. Every Keycloak call uses the caller's own `sub`; ids from the
 * request (credential, session) are checked against the caller's own list.
 */
class SecurityController extends Controller
{
    public function __construct(
        protected KeycloakDirectory $directory,
        protected PortalAudit $audit,
        protected SecurityNotifier $notifier,
    ) {}

    /** GET /account/profile */
    public function profile(Request $request): JsonResponse
    {
        $user = $this->directory->user($this->sub($request));

        if ($user === null) {
            return $this->unavailable();
        }

        $whatsapp = KeycloakDirectory::attribute($user, KeycloakDirectory::ATTR_WHATSAPP);

        return response()->json(['data' => [
            'name' => trim(($user['firstName'] ?? '').' '.($user['lastName'] ?? '')) ?: null,
            'username' => $user['username'] ?? null,
            'email' => $user['email'] ?? null,
            'email_verified' => (bool) ($user['emailVerified'] ?? false),
            'whatsapp' => PhoneNumber::mask($whatsapp),
            'whatsapp_verified_at' => KeycloakDirectory::attribute($user, KeycloakDirectory::ATTR_WHATSAPP_VERIFIED_AT),
            'employment' => $this->employment($user['username'] ?? null),
        ]]);
    }

    /** GET /account/security */
    public function security(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $user = $this->directory->user($sub);

        if ($user === null) {
            return $this->unavailable();
        }

        $hasRecovery = ! empty($user['email']) || KeycloakDirectory::attribute($user, KeycloakDirectory::ATTR_WHATSAPP) !== null;

        return response()->json([
            'data' => CredentialSummary::summarize($this->directory->credentials($sub), $hasRecovery)
                + ['sessions' => count($this->directory->sessions($sub))],
        ]);
    }

    /** GET /account/credentials */
    public function credentials(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CredentialSummary::present($this->directory->credentials($this->sub($request))),
        ]);
    }

    /** PATCH /account/credentials/{id} — rename */
    public function renameCredential(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:60']]);
        $sub = $this->sub($request);

        if (! CredentialSummary::owns($this->directory->credentials($sub), $id)) {
            return $this->notFound();
        }

        if (! $this->directory->renameCredential($sub, $id, trim($data['label']))) {
            return $this->unavailable();
        }

        $this->audit->record('credential_renamed', $sub, ClientIp::from($request), ['credential_id' => $id]);

        return response()->json(['data' => ['id' => $id, 'label' => trim($data['label'])]]);
    }

    /** DELETE /account/credentials/{id} — behind api.recent-auth */
    public function removeCredential(Request $request, string $id): JsonResponse
    {
        $sub = $this->sub($request);
        $credentials = $this->directory->credentials($sub);

        $blocked = CredentialSummary::removalBlockedReason($credentials, $id);

        if ($blocked === 'not_found') {
            return $this->notFound();
        }

        if ($blocked === 'last_authenticator') {
            return response()->json(['error' => [
                'code' => 'last_authenticator',
                'message' => 'Verifikasi dua langkah wajib untuk akun ASN. Tambahkan autentikator lain dulu, atau pakai "Ganti perangkat".',
            ]], 409);
        }

        if (! $this->directory->deleteCredential($sub, $id)) {
            return $this->unavailable();
        }

        $type = collect(CredentialSummary::present($credentials))->firstWhere('id', $id)['type'] ?? 'credential';
        $this->audit->record('credential_removed', $sub, ClientIp::from($request), ['credential_id' => $id, 'type' => $type]);
        $this->notifier->alert($this->email($sub), 'Cara masuk dihapus dari akun Anda', [
            'Satu cara masuk ('.$this->typeLabel($type).') baru saja dihapus lewat Akun ASN.',
            'Waktu: '.now('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB.',
        ]);

        return response()->json(null, 204);
    }

    /** GET /account/sessions */
    public function sessions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SessionPresenter::present(
                $this->directory->sessions($this->sub($request)),
                $this->directory->appNames(),
                $this->sid($request),
            ),
        ]);
    }

    /** DELETE /account/sessions/{id} */
    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $sub = $this->sub($request);

        if (! SessionPresenter::owns($this->directory->sessions($sub), $id)) {
            return $this->notFound();
        }

        if (! $this->directory->deleteSession($id)) {
            return $this->unavailable();
        }

        $this->audit->record('session_revoked', $sub, ClientIp::from($request), ['session_id' => $id]);

        return response()->json(null, 204);
    }

    /** POST /account/sessions/logout-others — behind api.recent-auth */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $current = $this->sid($request);
        $revoked = 0;

        foreach ($this->directory->sessions($sub) as $session) {
            $id = $session['id'] ?? null;

            if (is_string($id) && $id !== $current && $this->directory->deleteSession($id)) {
                $revoked++;
            }
        }

        $this->audit->record('other_sessions_revoked', $sub, ClientIp::from($request), ['count' => $revoked]);
        $this->notifier->alert($this->email($sub), 'Anda keluar dari perangkat lain', [
            "{$revoked} perangkat lain dikeluarkan dari akun Anda lewat Akun ASN.",
        ]);

        return response()->json(['data' => ['revoked' => $revoked]]);
    }

    /** GET /account/activity */
    public function activity(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $days = (int) config('nawasara-asn-portal.activity_days', 90);

        return response()->json([
            'data' => ActivityPresenter::merge(
                $this->directory->events($sub, $days),
                $this->audit->eventsFor($sub, $days),
                $this->directory->appNames(),
            ),
            'meta' => ['days' => $days],
        ]);
    }

    /**
     * Employment data, when the registry knows this NIP (synced from
     * SimasHebat for staff that have a panel account). Null otherwise: the
     * token carries no employment data, and guessing is worse than blank.
     *
     * @return array<string, ?string>|null
     */
    protected function employment(?string $nip): ?array
    {
        if (! is_string($nip) || preg_match('/^\d{18}$/', $nip) !== 1
            || ! \Illuminate\Support\Facades\Schema::hasTable('nawasara_registry_employees')) {
            return null;
        }

        $row = \Illuminate\Support\Facades\DB::table('nawasara_registry_employees')->where('nip', $nip)->first();

        if ($row === null) {
            return null;
        }

        $unit = null;

        if (isset($row->unit_id) && \Illuminate\Support\Facades\Schema::hasTable('nawasara_registry_units')) {
            $unit = \Illuminate\Support\Facades\DB::table('nawasara_registry_units')->where('id', $row->unit_id)->value('name');
        }

        return [
            'position' => $row->nama_jabatan ?? null,
            'position_type' => $row->jenis_jabatan ?? null,
            'employment_type' => $row->jenis_kepegawaian ?? null,
            'unit' => is_string($unit) ? $unit : null,
        ];
    }

    protected function email(string $sub): ?string
    {
        $email = $this->directory->user($sub)['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            'passkey' => 'passkey',
            'totp' => 'aplikasi autentikator',
            'security_key' => 'kunci keamanan',
            'backup_codes' => 'kode cadangan',
            default => 'cara masuk',
        };
    }

    protected function sub(Request $request): string
    {
        return (string) $request->attributes->get('staff_sub');
    }

    protected function sid(Request $request): ?string
    {
        $sid = $request->attributes->get('staff_claims')['sid'] ?? null;

        return is_string($sid) ? $sid : null;
    }

    protected function notFound(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'not_found', 'message' => 'Tidak ditemukan.']], 404);
    }

    /**
     * Keycloak did not answer as expected. 503, not 500: it is a dependency
     * being unavailable, and the portal can say "coba lagi".
     */
    protected function unavailable(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'identity_unavailable',
            'message' => 'Layanan akun sedang tidak dapat dihubungi. Silakan coba lagi.',
        ]], 503);
    }
}
