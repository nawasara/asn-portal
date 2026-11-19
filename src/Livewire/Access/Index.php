<?php

namespace Nawasara\AsnPortal\Livewire\Access;

use Livewire\Component;

/**
 * Akses Layanan Kisara: page shell only. Per-app switches, the person list
 * and the add/edit form are their own components (AGENTS §1b).
 */
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('asn-portal.access.view');
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.access.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
