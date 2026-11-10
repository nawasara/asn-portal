<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Nawasara\AsnPortal\Services\MailboxDirectory;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\StaffDirectory;
use Nawasara\AsnPortal\Support\AccountIdentity;
use Nawasara\AsnPortal\Support\ClientIp;
use Nawasara\AsnPortal\Support\PgpEvent;

/**
 * Endpoints for Kisara Mail (patra.ponorogo.go.id), mounted at
 * /api/v1/asn-portal/mail/* behind api.client:<Kisara Mail client>.
 *
 * Kept apart from /account/* on purpose: those routes can sign the user
 * out, reveal VPN configs and change passwords, and only the account
 * portal may call them. Kisara Mail gets exactly what a webmail needs.
 *
 * The mailbox list is the same MailboxDirectory the account portal uses,
 * so both apps always agree on who may open what (FSD FR-02, FR-03).
 */
class MailController extends Controller
{
    public const CHANNEL = 'kisara-mail';

    public function __construct(
        protected MailboxDirectory $mailboxes,
        protected PortalAudit $audit,
    ) {}

    /** GET /mail/mailboxes */
    public function mailboxes(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->mailboxes->forUser($this->sub($request), $this->nip($request))->map(fn (array $m) => self::present($m))->values(),
        ]);
    }

    /**
     * POST /mail/mailboxes/{address}/opened — Kisara Mail is about to open
     * this mailbox (once per session). Recorded with the channel so the
     * shared mailbox's other holders see it next to webmail openings, and
     * so usage of the two channels can be compared (FSD FR-05, FR-25).
     *
     * 503 when the row cannot be written: for a shared mailbox Kisara Mail
     * then refuses to open it, because an unrecorded opening is exactly what
     * the access history exists to prevent.
     */
    public function opened(Request $request, string $address): Response|JsonResponse
    {
        $mailbox = $this->mailboxes->find($this->sub($request), $this->nip($request), urldecode($address));

        if ($mailbox === null) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Kotak email tidak ditemukan.']], 404);
        }

        try {
            $this->audit->recordOrFail('mailbox_opened', $this->sub($request), ClientIp::from($request), [
                'mailbox' => $mailbox['address'],
                'kind' => $mailbox['kind'],
                'channel' => self::CHANNEL,
            ]);
        } catch (\Throwable $e) {
            Log::error('asn-portal: mailbox_opened audit write failed', ['mailbox' => $mailbox['address'], 'error' => class_basename($e)]);

            return response()->json(['error' => [
                'code' => 'audit_unavailable',
                'message' => 'Pencatatan akses sedang tidak tersedia. Silakan coba lagi.',
            ]], 503);
        }

        return response()->noContent();
    }

    /**
     * POST /mail/mailboxes/{address}/pgp-events — Kisara Mail is about to do
     * something with this mailbox's OpenPGP key (Kisara Mail PGP FSD FR-12):
     * hand out a holder's locked copy, give or refuse another holder access,
     * replace the key. Shown in the same access history as openings.
     *
     * Same rule as `opened`: 503 when the row cannot be written, and Kisara
     * Mail then refuses the action for a shared mailbox. `target_sub` names
     * the other holder for grant/refuse; it must hold the mailbox too.
     */
    public function pgpEvent(Request $request, string $address): Response|JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'fingerprint' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{40}$/'],
            'target_sub' => ['nullable', 'string', 'max:64'],
        ]);

        if (! PgpEvent::isKnown($data['type'])) {
            return response()->json(['error' => ['code' => 'unknown_type', 'message' => 'Jenis kejadian tidak dikenal.']], 422);
        }

        $mailbox = $this->mailboxes->find($this->sub($request), $this->nip($request), urldecode($address));

        if ($mailbox === null) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Kotak email tidak ditemukan.']], 404);
        }

        $extra = [
            'mailbox' => $mailbox['address'],
            'kind' => $mailbox['kind'],
            'channel' => self::CHANNEL,
            'type' => $data['type'],
        ];
        if (! empty($data['fingerprint'])) {
            $extra['fingerprint'] = strtoupper($data['fingerprint']);
        }
        if (in_array($data['type'], PgpEvent::WITH_TARGET, true) && ! empty($data['target_sub'])) {
            $extra['target_sub'] = $data['target_sub'];
        }

        try {
            $this->audit->recordOrFail(PortalAudit::MAILBOX_PGP_EVENT, $this->sub($request), ClientIp::from($request), $extra);
        } catch (\Throwable $e) {
            Log::error('asn-portal: mailbox_pgp_event audit write failed', ['mailbox' => $mailbox['address'], 'type' => $data['type'], 'error' => class_basename($e)]);

            return response()->json(['error' => [
                'code' => 'audit_unavailable',
                'message' => 'Pencatatan akses sedang tidak tersedia. Silakan coba lagi.',
            ]], 503);
        }

        return response()->noContent();
    }

    /**
     * POST /mail/mailboxes/{address}/webmail — the way out to the old
     * webmail (FSD FR-24), e.g. when Kisara Mail cannot open a mailbox.
     *
     * Deliberately the SAME code as Akun Kisara's webmail button: same
     * mailbox check, same one-time WHM session, same `webmail_opened` record.
     * A second implementation would drift, and this is the path that hands
     * out a login.
     */
    public function webmail(Request $request, string $address): JsonResponse
    {
        return app(MailboxController::class)->webmail($request, $address);
    }

    /**
     * GET /mail/directory?q= — ASN address book for recipient suggestions
     * (FSD FR-19): name, unit and email of personal mailboxes, at most 10.
     * No NIP, phone or other personal data.
     */
    public function directory(Request $request, StaffDirectory $directory): JsonResponse
    {
        return response()->json(['data' => $directory->search((string) $request->query('q', ''))]);
    }

    /**
     * Allow-list of what leaves for Kisara Mail. The directory row also
     * carries the WHM instance and the other PICs; neither is needed by a
     * webmail, so neither is sent.
     *
     * @param  array<string, mixed>  $m
     * @return array{address: string, kind: string, role: ?string}
     */
    public static function present(array $m): array
    {
        return [
            'address' => (string) $m['address'],
            // "agency" in Nawasara = shared in Kisara Mail's vocabulary (FSD §1).
            'kind' => $m['kind'] === 'personal' ? 'personal' : 'shared',
            'role' => isset($m['role']) ? (string) $m['role'] : null,
        ];
    }

    private function sub(Request $request): string
    {
        return (string) $request->attributes->get('staff_sub');
    }

    private function nip(Request $request): ?string
    {
        return AccountIdentity::fromClaims((array) $request->attributes->get('staff_claims'))['nip'];
    }
}
