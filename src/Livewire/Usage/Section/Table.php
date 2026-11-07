<?php

namespace Nawasara\AsnPortal\Livewire\Usage\Section;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\AsnPortal\Services\MailUsage;

/**
 * Weekly openings per channel: Roundcube (via Akun Kisara) vs Kisara Mail.
 * Read-only; the audit trail behind it is written by the two apps.
 */
class Table extends Component
{
    /** How many weeks back; the trail itself keeps 52. */
    #[Url]
    public int $weeks = 12;

    #[Computed]
    public function rows(): array
    {
        return app(MailUsage::class)->weekly(in_array($this->weeks, [4, 12, 26, 52], true) ? $this->weeks : 12);
    }

    public function refreshData(): void
    {
        unset($this->rows);
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.usage.section.table');
    }
}
