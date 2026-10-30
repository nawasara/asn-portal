<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\KeycloakDirectory;
use Nawasara\AsnPortal\Services\OtpChallenge;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\SecurityNotifier;
use Nawasara\AsnPortal\Services\WhatsappGateway;
use Nawasara\AsnPortal\Support\ClientIp;
use Nawasara\AsnPortal\Support\PhoneNumber;

/**
 * Change the WhatsApp number stored on the Keycloak account
 * (attribute `whatsapp_number`), which the MFA reset can send codes to.
 *
 * Behind api.recent-auth: whoever controls this number can reset two-step
 * verification when the account has no email, so a borrowed, long-open
 * session must not be enough to change it.
 *
 * Verification by WhatsApp code follows ASN_PORTAL_WA_VERIFICATION, which
 * defaults to OFF: production runs with verification disabled since the
 * WAGO number was banned (project_wa_verification_skipped). Switching it on
 * silently during the migration would break the flow while the gateway is
 * still down. Off = the number is saved as typed, like the old portal does
 * today.
 */
class WhatsappController extends Controller
{
    private const PURPOSE = 'whatsapp';

    public function __construct(
        protected KeycloakDirectory $directory,
        protected OtpChallenge $otp,
        protected WhatsappGateway $gateway,
        protected PortalAudit $audit,
        protected SecurityNotifier $notifier,
    ) {}

    /** POST /account/whatsapp {number} */
    public function request(Request $request): JsonResponse
    {
        $number = PhoneNumber::normalize((string) $request->input('number'));

        if ($number === null) {
            return $this->invalid('Nomor harus nomor seluler Indonesia, diawali 08, contoh 081234567890.');
        }

        $sub = $this->sub($request);

        if (! (bool) config('nawasara-asn-portal.whatsapp.verification_enabled', false)) {
            return $this->save($request, $sub, $number, verified: false);
        }

        try {
            if (! $this->gateway->isRegistered($number)) {
                return $this->invalid('Nomor ini tidak terdaftar di WhatsApp. Gunakan nomor lain.');
            }
        } catch (\RuntimeException $e) {
            return $this->gatewayDown($e->getMessage());
        }

        $identity = $sub.'|'.$number;
        $ip = ClientIp::from($request);
        $code = $this->otp->issue(self::PURPOSE, $identity, $ip);

        if ($code === null) {
            return $this->tooMany();
        }

        try {
            $this->gateway->send($number, "Kode verifikasi nomor WhatsApp Akun ASN: {$code}. Berlaku {$this->otp->ttlMinutes()} menit. Jangan berikan kode ini kepada siapa pun.");
        } catch (\RuntimeException $e) {
            $this->otp->discard(self::PURPOSE, $identity);

            return $this->gatewayDown($e->getMessage());
        }

        $this->otp->markSent(self::PURPOSE, $identity, $ip);

        return response()->json(['data' => [
            'status' => 'code_sent',
            'destination' => PhoneNumber::mask($number),
            'ttl_minutes' => $this->otp->ttlMinutes(),
        ]]);
    }

    /** POST /account/whatsapp/verify {number, code} */
    public function verify(Request $request): JsonResponse
    {
        $number = PhoneNumber::normalize((string) $request->input('number'));

        if ($number === null) {
            return $this->invalid('Nomor tidak valid.');
        }

        $sub = $this->sub($request);
        $result = $this->otp->verify(self::PURPOSE, $sub.'|'.$number, (string) $request->input('code'));

        return match ($result) {
            'valid' => $this->save($request, $sub, $number, verified: true),
            'invalid' => response()->json(['error' => ['code' => 'invalid_code', 'message' => 'Kode tidak cocok.']], 422),
            default => response()->json(['error' => ['code' => 'code_expired', 'message' => 'Kode sudah tidak berlaku. Minta kode baru.']], 422),
        };
    }

    private function save(Request $request, string $sub, string $number, bool $verified): JsonResponse
    {
        $user = $this->directory->user($sub);
        $old = KeycloakDirectory::attribute($user, KeycloakDirectory::ATTR_WHATSAPP);

        // `whatsapp_verified_at` is written only when the number was proven
        // with a code. The old portal wrote it on every save, which made an
        // unverified number look verified.
        $ok = $this->directory->setAttributes($sub, [
            KeycloakDirectory::ATTR_WHATSAPP => $number,
            KeycloakDirectory::ATTR_WHATSAPP_VERIFIED_AT => $verified ? now()->toDateTimeString() : null,
        ]);

        if (! $ok) {
            return response()->json(['error' => [
                'code' => 'identity_unavailable',
                'message' => 'Layanan akun sedang tidak dapat dihubungi. Silakan coba lagi.',
            ]], 503);
        }

        $this->audit->record('whatsapp_changed', $sub, ClientIp::from($request), ['verified' => $verified]);
        $this->notifier->alert($user['email'] ?? null, 'Nomor WhatsApp akun Anda diubah', [
            'Nomor WhatsApp pemulihan diubah menjadi '.PhoneNumber::mask($number).'.',
            $old !== null ? 'Nomor sebelumnya: '.PhoneNumber::mask($old).'.' : 'Sebelumnya belum ada nomor tersimpan.',
        ]);

        return response()->json(['data' => [
            'status' => 'saved',
            'whatsapp' => PhoneNumber::mask($number),
            'verified' => $verified,
        ]]);
    }

    private function sub(Request $request): string
    {
        return (string) $request->attributes->get('staff_sub');
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'invalid_number', 'message' => $message]], 422);
    }

    private function gatewayDown(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'whatsapp_unavailable', 'message' => $message.' Silakan coba beberapa saat lagi.']], 503);
    }

    private function tooMany(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'too_many_codes', 'message' => 'Pengiriman kode dibatasi. Silakan coba lagi nanti.']], 429);
    }
}
