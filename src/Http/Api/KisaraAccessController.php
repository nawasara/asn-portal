<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Http\Resources\KisaraAccessResource;
use Nawasara\AsnPortal\Services\KisaraAccess;

/**
 * GET /api/v1/kisara/access/{nip}?app=mail   scope: asn-portal.access.read
 *
 * May this ASN use this Kisara app right now? Asked by the app's server
 * (Kisara Mail first) at login and about once a minute after, never by a
 * browser.
 *
 * Always 200 with `allowed` for a known app: a refusal is an answer, not an
 * error, and the app shows its own "sedang uji coba" page for it. A NIP that
 * is not 18 digits is a refusal too (`invalid_nip`), because the caller
 * passes the login name as it is and an admin account is not an ASN. Only a
 * missing or unknown `app` is the caller's mistake (422), and a missing or
 * wrong token is rejected by middleware (401/403).
 */
class KisaraAccessController extends Controller
{
    public function show(Request $request, string $nip, KisaraAccess $access): JsonResponse
    {
        $app = (string) $request->query('app', '');

        if (! KisaraAccess::knows($app)) {
            return response()->json([
                'error' => [
                    'code' => KisaraAccess::UNKNOWN_APP,
                    'message' => 'Parameter app wajib berisi salah satu: '.implode(', ', array_keys(KisaraAccess::apps())).'.',
                ],
            ], 422);
        }

        return response()->json([
            'data' => (new KisaraAccessResource($access->allows($app, $nip)))->resolve(),
        ]);
    }
}
