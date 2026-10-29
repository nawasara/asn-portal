<?php

namespace Nawasara\AsnPortal\Livewire\App;

use Livewire\Component;

/**
 * App catalog page: shell only, the content lives in Section/Table.
 */
class Index extends Component
{
    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.app.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
