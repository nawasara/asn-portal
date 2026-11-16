<?php

namespace Nawasara\AsnPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allow-list of catalog fields for one launcher entry. No database id: the
 * slug is the stable key. No Keycloak client id, no audience: callers only
 * need what to draw and where it links.
 */
class LauncherItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->slug,
            'label' => $this->launcher_label ?? $this->name,
            'name' => $this->name,
            'url' => $this->url,
            'icon' => $this->launcher_icon,
            'icon_url' => $this->icon_url,
            'abbreviation' => $this->abbreviation,
            'color' => $this->color,
            'section' => $this->launcher_section,
            'order' => $this->launcher_sort,
        ];
    }
}
