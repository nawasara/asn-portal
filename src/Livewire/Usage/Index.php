<?php

namespace Nawasara\AsnPortal\Livewire\Usage;

use Livewire\Component;

/**
 * Mail usage per channel (Kisara Mail FR-25): shell only, the numbers live in Section/Table.
 */
class Index extends Component
{
    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.usage.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
