<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\ServerDirectory;

class ServerController extends Controller
{
    public function __invoke(Request $request, ServerDirectory $servers): JsonResponse
    {
        return response()->json(['data' => $servers->forUser((string) $request->attributes->get('staff_sub'))]);
    }
}
