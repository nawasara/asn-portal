<?php

namespace Nawasara\AsnPortal\Livewire\Legal;

use Livewire\Component;

/**
 * Legal documents page: shell only, the content lives in Section/Table.
 */
class Index extends Component
{
    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.legal.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
