<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\MailboxDirectory;

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
