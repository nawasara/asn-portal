<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\SiteDirectory;

/**
 * GET /account/sites — the agency sites this civil servant is a PIC of.
 *
 * Findings are returned as counts only. Their evidence (matched spam
 * keywords, defaced page content, database names) stays in the panel,
 * where the security team works on them.
 */
class SiteController extends Controller
{
    public function __invoke(Request $request, SiteDirectory $sites): JsonResponse
    {
        return response()->json(['data' => $sites->forUser((string) $request->attributes->get('staff_sub'))]);
    }
}
