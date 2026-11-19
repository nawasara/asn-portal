<?php

namespace Nawasara\AsnPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allow-list of what a Kisara app learns about one person's access
 * (AGENTS §10a). Every key is named here; nothing is passed through, so a
 * future field on the decision (the OPD override, a note) cannot leak by
 * default. No name, e-mail or OPD: the caller already has the name from the
 * login token, and only Workspace needs the OPD (its own API gives it).
 */
class KisaraAccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'nip' => $r['nip'],
            'app' => $r['app'],
            'allowed' => $r['allowed'],
            'reason' => $r['reason'],
            'stage' => $r['stage'],
        ];
    }
}
