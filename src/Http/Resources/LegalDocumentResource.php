<?php

namespace Nawasara\AsnPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegalDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'version' => $this->version,
            'title' => $this->title,
            // Markdown. The portal renders it with HTML disabled.
            'body' => $this->body,
            'effective_at' => $this->effective_at?->toIso8601String(),
        ];
    }
}
