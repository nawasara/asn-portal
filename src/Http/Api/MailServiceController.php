<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Nawasara\AsnPortal\Services\MailboxDirectory;
use Nawasara\AsnPortal\Services\PortalAudit;

/**
 * Server-to-server checks for Kisara Mail, behind its nws_ token.
 *
 * POST /mail-service/holdings — does each (sub, NIP, mailbox) still hold
 * the mailbox? Kisara Mail's push watcher asks before every notification
 * round, so a revoked NIP link or PIC stops notifications even while the
 * person is not signed in (FSD FR-23). The user endpoints cannot do this:
 * they need the user's own token, and the watcher has none.
 *
 * Answers only yes/no per pair it was asked about. It never lists who holds
 * a mailbox, so the token cannot be used to enumerate the directory.
 */
class MailServiceController extends Controller
{
    public const MAX_CHECKS = 200;

    public function __construct(protected MailboxDirectory $mailboxes) {}

    public function holdings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'checks' => ['required', 'array', 'max:'.self::MAX_CHECKS],
            'checks.*.sub' => ['required', 'string', 'max:64'],
            'checks.*.nip' => ['nullable', 'string', 'regex:/^\d{18}$/'],
            'checks.*.mailbox' => ['required', 'string', 'email', 'max:320'],
        ]);

        $out = self::answer($data['checks'], fn (string $sub, ?string $nip) => $this->mailboxes->forUser($sub, $nip)->pluck('address')->all());

        return response()->json(['data' => $out]);
    }

    /**
     * POST /mail-service/pgp-revoked — Kisara Mail's periodic holdings check
     * deleted the PGP key copy of someone who no longer holds a mailbox
     * (Kisara Mail PGP FSD FR-11). Recorded under that person's sub so the
     * remaining holders see whose access ended.
     *
     * Server-to-server: nobody is signed in, and the person no longer holds
     * the mailbox, so there is no holder check; the token's scope is the
     * same holdings scope whose answer caused the revocation.
     */
    public function pgpRevoked(Request $request, PortalAudit $audit): Response|JsonResponse
    {
        $data = $request->validate([
            'mailbox' => ['required', 'string', 'email', 'max:320'],
            'sub' => ['required', 'string', 'max:64'],
            'fingerprint' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{40}$/'],
        ]);

        $extra = ['mailbox' => strtolower($data['mailbox']), 'channel' => MailController::CHANNEL, 'type' => 'pgp.copy_revoked'];
        if (! empty($data['fingerprint'])) {
            $extra['fingerprint'] = strtoupper($data['fingerprint']);
        }

        try {
            $audit->recordOrFail(PortalAudit::MAILBOX_PGP_EVENT, $data['sub'], null, $extra);
        } catch (\Throwable $e) {
            Log::error('asn-portal: pgp revocation audit write failed', ['mailbox' => $extra['mailbox'], 'error' => class_basename($e)]);

            return response()->json(['error' => ['code' => 'audit_unavailable', 'message' => 'Pencatatan sedang tidak tersedia.']], 503);
        }

        return response()->noContent();
    }

    /**
     * Pure part, testable without HTTP: one directory lookup per person,
     * however many mailboxes are asked about; comparison ignores case.
     *
     * @param  list<array{sub: string, nip?: ?string, mailbox: string}>  $checks
     * @param  \Closure(string, ?string): list<string>  $addressesFor
     * @return list<array{sub: string, mailbox: string, held: bool}>
     */
    public static function answer(array $checks, \Closure $addressesFor): array
    {
        $held = [];
        $out = [];
        foreach ($checks as $check) {
            $nip = $check['nip'] ?? null;
            $key = $check['sub'].'|'.($nip ?? '');
            $held[$key] ??= array_map('strtolower', $addressesFor($check['sub'], $nip));
            $mailbox = strtolower($check['mailbox']);
            $out[] = ['sub' => $check['sub'], 'mailbox' => $mailbox, 'held' => in_array($mailbox, $held[$key], true)];
        }

        return $out;
    }
}
