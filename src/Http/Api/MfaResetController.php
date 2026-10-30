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
 * Reset two-step verification WITHOUT signing in, for civil servants who
 * lost their authenticator phone (plan §4a).
 *
 * This is an anonymous write to an identity, so the guards are layered:
 *
 * - The portal server calls it with its own nws_ token, scope
 *   `asn-portal.mfa.reset` only, IP allow-listed on the token. A browser
 *   cannot call it directly.
 * - The API never accepts a Keycloak user id; it takes a NIP and resolves
 *   the user inside Nawasara.
 * - The code is bound to the NIP that asked for it, stored as an HMAC,
 *   limited in sends per NIP and real IP, and burned after a few wrong
 *   attempts (OtpChallenge).
 * - Every reset is audited and announced to the account's email.
 *
 * Channel: email first; WhatsApp only when the account has no email, as in
 * the old portal.
 */
class MfaResetController extends Controller
{
    private const PURPOSE = 'mfa-reset';

    public function __construct(
        protected KeycloakDirectory $directory,
        protected OtpChallenge $otp,
        protected WhatsappGateway $gateway,
        protected SecurityNotifier $notifier,
        protected PortalAudit $audit,
    ) {}

    /** POST /public/mfa-reset/request {nip} */
    public function request(Request $request): JsonResponse
    {
        $nip = (string) $request->input('nip');

        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return response()->json(['error' => ['code' => 'invalid_nip', 'message' => 'NIP harus 18 digit.']], 422);
        }

        $user = $this->directory->findByNip($nip);

        if ($user === null || ($user['username'] ?? null) !== $nip || ! is_string($user['id'] ?? null)) {
            return response()->json(['error' => ['code' => 'nip_not_found', 'message' => 'NIP tidak terdaftar.']], 404);
        }

        $email = is_string($user['email'] ?? null) && $user['email'] !== '' ? $user['email'] : null;
        $whatsapp = KeycloakDirectory::attribute($user, KeycloakDirectory::ATTR_WHATSAPP);

        if ($email === null && $whatsapp === null) {
            return response()->json(['error' => [
                'code' => 'no_recovery_contact',
                'message' => 'Akun ini belum punya email maupun nomor WhatsApp. Silakan hubungi RAKACA.',
            ]], 422);
        }

        $ip = ClientIp::from($request);
        $code = $this->otp->issue(self::PURPOSE, $nip, $ip);

        if ($code === null) {
            return response()->json(['error' => [
                'code' => 'too_many_codes',
                'message' => 'Pengiriman kode dibatasi. Silakan coba lagi nanti.',
            ]], 429);
        }

        try {
            if ($email !== null) {
                $this->notifier->sendCode($email, $code, $this->otp->ttlMinutes(), 'reset verifikasi dua langkah');
                $channel = 'email';
                $destination = PhoneNumber::maskEmail($email);
            } else {
                $this->gateway->send($whatsapp, "Kode reset verifikasi dua langkah Akun ASN: {$code}. Berlaku {$this->otp->ttlMinutes()} menit. Jangan berikan kode ini kepada siapa pun.");
                $channel = 'whatsapp';
                $destination = PhoneNumber::mask($whatsapp);
            }
        } catch (\RuntimeException $e) {
            // Not delivered: forget the code and do not count the attempt.
            $this->otp->discard(self::PURPOSE, $nip);

            return response()->json(['error' => [
                'code' => 'delivery_failed',
                'message' => $e->getMessage().' Silakan coba lagi.',
            ]], 503);
        }

        $this->otp->markSent(self::PURPOSE, $nip, $ip);

        return response()->json(['data' => [
            'channel' => $channel,
            'destination' => $destination,
            'ttl_minutes' => $this->otp->ttlMinutes(),
        ]]);
    }

    /** POST /public/mfa-reset/verify {nip, code} */
    public function verify(Request $request): JsonResponse
    {
        $nip = (string) $request->input('nip');
        $result = $this->otp->verify(self::PURPOSE, $nip, (string) $request->input('code'));

        if ($result === 'invalid') {
            return response()->json(['error' => ['code' => 'invalid_code', 'message' => 'Kode tidak cocok.']], 422);
        }

        if ($result !== 'valid') {
            return response()->json(['error' => ['code' => 'code_expired', 'message' => 'Kode sudah tidak berlaku. Minta kode baru.']], 422);
        }

        $user = $this->directory->findByNip($nip);

        if ($user === null || ! is_string($user['id'] ?? null) || ! $this->directory->resetAuthenticators($user['id'])) {
            return response()->json(['error' => [
                'code' => 'identity_unavailable',
                'message' => 'Layanan akun sedang tidak dapat dihubungi. Silakan coba lagi.',
            ]], 503);
        }

        $ip = ClientIp::from($request);
        $this->audit->record('mfa_reset', $user['id'], $ip, ['nip' => $nip]);
        $this->notifier->alert($user['email'] ?? null, 'Verifikasi dua langkah akun Anda direset', [
            'Autentikator akun Anda baru saja dihapus lewat Reset verifikasi dua langkah di Akun ASN.',
            'Saat masuk berikutnya, Anda diminta memasang autentikator baru.',
            'Waktu: '.now('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB, dari alamat '.$ip.'.',
        ]);

        return response()->json(['data' => ['reset' => true]]);
    }
}
