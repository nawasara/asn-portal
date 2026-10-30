<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\Api\Http\Middleware\RequireRecentAuth;
use Nawasara\AsnPortal\Support\AccountActions;
use Nawasara\AsnPortal\Support\AccountIdentity;

/**
 * "My account" endpoints. Identity always comes from the verified token
 * (request attribute `staff_claims`, set by api.staff.identity), never from
 * a parameter, so there is nobody else's account to reach.
 */
class AccountController extends Controller
{
    /** GET /account — who is signed in. */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => AccountIdentity::fromClaims($this->claims($request)),
        ]);
    }

    /**
     * POST /account/actions/{action} — authorize parameters for a Keycloak
     * required action (register a passkey, set up an authenticator, create
     * backup codes). See AccountActions for why the portal asks instead of
     * deciding.
     */
    public function startAction(Request $request, string $action): JsonResponse
    {
        if (! AccountActions::supports($action, (bool) config('nawasara-asn-portal.keycloak.backup_codes', false))) {
            return response()->json([
                'error' => ['code' => 'unknown_action', 'message' => 'Aksi tidak dikenal.'],
            ], 404);
        }

        $recent = RequireRecentAuth::isRecent(
            $this->claims($request),
            (int) config('nawasara-asn-portal.recent_auth_seconds', RequireRecentAuth::DEFAULT_MAX_AGE),
            time(),
        );

        return response()->json([
            'data' => [
                'action' => $action,
                'authorize_params' => AccountActions::authorizeParams($action, $recent),
            ],
        ]);
    }

    /**
     * POST /account/reauth — parameters to force a fresh login, for the
     * portal to use after an API call answered `reauth_required`.
     */
    public function reauth(): JsonResponse
    {
        return response()->json([
            'data' => ['authorize_params' => AccountActions::reauthParams()],
        ]);
    }

    /** @return array<string, mixed> */
    private function claims(Request $request): array
    {
        $claims = $request->attributes->get('staff_claims');

        return is_array($claims) ? $claims : [];
    }
}
