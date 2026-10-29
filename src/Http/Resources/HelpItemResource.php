<?php

namespace Nawasara\AsnPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HelpItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'kind' => $this->kind,
            'question' => $this->question,
            // Markdown. The portal renders it with HTML disabled.
            'answer' => $this->answer,
            'action' => $this->action_url ? [
                'label' => $this->action_label,
                'url' => $this->action_url,
            ] : null,
        ];
    }
}
