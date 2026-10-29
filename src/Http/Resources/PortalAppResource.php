<?php

namespace Nawasara\AsnPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allow-list of catalog fields for the PUBLIC directory. The Keycloak client
 * id is left out: guests have no use for it, and the list of client ids in
 * the employee realm is not something to publish.
 */
class PortalAppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'category' => $this->category,
            'abbreviation' => $this->abbreviation,
            'color' => $this->color,
            'icon_url' => $this->icon_url,
        ];
    }
}
