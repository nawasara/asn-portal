<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Nawasara\AsnPortal\Services\KeycloakDirectory;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\SecurityNotifier;
use Nawasara\AsnPortal\Support\EmailChange;
use Nawasara\AsnPortal\Support\PhoneNumber;

/**
 * SIMAS Hebat tells Nawasara that a civil servant's email changed.
 *
 * Why it exists: Keycloak took the email from SIMAS Hebat only on a
 * successful login. Someone who could not log in (lost authenticator) asked
 * for an OTP reset and the link went to the OLD address. Pushing the change
 * at the moment it is made in SIMAS Hebat closes that gap.
 *
 * Changing the email of an account decides where its reset links go, so the
 * guards are those of the MFA reset endpoint:
 *
 * - Called only by the SIMAS Hebat SERVER with its own nws_ token, scope
 *   `asn-portal.staff.email.write` and nothing else, IP allow-listed.
 * - Takes a NIP, never a Keycloak id; the account is resolved here, by exact
 *   username.
 * - Never moves an email that another account already uses (409).
 * - Every change is audited with both addresses, and the OLD address is told
 *   about it, so a change the owner did not make is noticed by the owner.
 */
class StaffEmailController extends Controller
{
    public function __construct(
        protected KeycloakDirectory $directory,
        protected PortalAudit $audit,
        protected SecurityNotifier $notifier,
    ) {}

    /** PUT /staff/{nip}/email {email} */
    public function update(Request $request, string $nip): JsonResponse
    {
        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return $this->error('invalid_nip', 'NIP harus 18 digit.', 422);
        }

        $email = EmailChange::normalize($request->input('email'));

        if ($email === null) {
            return $this->error('invalid_email', 'Alamat email tidak valid.', 422);
        }

        $client = $this->directory->client();

        try {
            $user = $client->findUserByUsername($nip);
        } catch (\Throwable $e) {
            Log::warning('asn-portal: staff email lookup failed', ['reason' => $e->getMessage()]);

            return $this->unavailable();
        }

        if ($user === null || ! is_string($user['id'] ?? null)) {
            // Never signed in to Kisara: there is no account yet. The first
            // login creates it with the email SIMAS Hebat returns then.
            return $this->error('nip_not_found', 'NIP belum punya akun Kisara. Email akan diambil dari SIMAS Hebat saat login pertama.', 404);
        }

        $current = is_string($user['email'] ?? null) && $user['email'] !== '' ? $user['email'] : null;

        try {
            $owner = $this->ownerOf($email);
        } catch (\Throwable $e) {
            Log::warning('asn-portal: staff email owner check failed', ['reason' => $e->getMessage()]);

            return $this->unavailable();
        }

        $decision = EmailChange::decide($current, $email, $owner, $user['id']);

        if ($decision === EmailChange::UNCHANGED) {
            return response()->json(['data' => ['nip' => $nip, 'updated' => false]]);
        }

        if ($decision === EmailChange::CONFLICT) {
            return $this->error('email_in_use', 'Email ini sudah dipakai akun Kisara lain. Hubungi admin Kisara.', 409);
        }

        // Keycloak's PUT replaces top-level fields: send the whole user back.
        $user['email'] = $email;
        // SIMAS Hebat is the source of truth for this address, as on login
        // (the authenticator marks a changed email verified too).
        $user['emailVerified'] = true;

        if (! $client->updateUser($user['id'], $user)) {
            return $this->unavailable();
        }

        $this->audit->record('email_updated_from_simas', $user['id'], $request->ip(), [
            'nip' => $nip,
            'from' => $current,
            'to' => $email,
        ]);

        $this->notifier->alert($current, 'Email akun Kisara Anda diganti', [
            'Email akun Kisara Anda baru saja diganti menjadi '.PhoneNumber::maskEmail($email).', mengikuti perubahan data di SIMAS Hebat.',
            'Mulai sekarang tautan reset dan pemberitahuan keamanan dikirim ke alamat baru itu.',
            'Bila Anda tidak mengubah email di SIMAS Hebat, segera hubungi pengelola kepegawaian OPD Anda atau RAKACA.',
            'Waktu: '.now('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB.',
        ]);

        return response()->json(['data' => ['nip' => $nip, 'updated' => true]]);
    }

    /** Keycloak id of the account that already uses this email, if any. */
    protected function ownerOf(string $email): ?string
    {
        foreach ($this->directory->client()->getUsers(['email' => $email, 'exact' => 'true', 'max' => 5]) as $u) {
            // `exact` is not honoured by every Keycloak version: compare ourselves.
            if (is_string($u['email'] ?? null) && mb_strtolower($u['email']) === $email && is_string($u['id'] ?? null)) {
                return $u['id'];
            }
        }

        return null;
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    protected function unavailable(): JsonResponse
    {
        return $this->error('identity_unavailable', 'Layanan akun sedang tidak dapat dihubungi. Silakan coba lagi.', 503);
    }
}
