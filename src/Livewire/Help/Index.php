<?php

namespace Nawasara\AsnPortal\Livewire\Help;

use Livewire\Component;

/**
 * Help content page: shell only, the content lives in Section/Table.
 */
class Index extends Component
{
    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.help.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
