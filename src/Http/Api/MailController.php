<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\MailboxDirectory;
use Nawasara\AsnPortal\Support\AccountIdentity;

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
    public function __construct(protected MailboxDirectory $mailboxes) {}

    /** GET /mail/mailboxes */
    public function mailboxes(Request $request): JsonResponse
    {
        $sub = (string) $request->attributes->get('staff_sub');
        $nip = AccountIdentity::fromClaims((array) $request->attributes->get('staff_claims'))['nip'];

        return response()->json([
            'data' => $this->mailboxes->forUser($sub, $nip)->map(fn (array $m) => self::present($m))->values(),
        ]);
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
}
