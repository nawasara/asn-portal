<?php

namespace Nawasara\AsnPortal\Livewire\Access\Section;

use Livewire\Attributes\On;
use Livewire\Component;
use Nawasara\AsnPortal\Models\AppAccess;
use Nawasara\AsnPortal\Services\KisaraAccess;
use Nawasara\AuthPrimitives\Traits\WithSudo;

/**
 * One card per Kisara app: how many people may use it, and its two
 * switches.
 *
 * - Matikan untuk semua (kill switch): nobody may use the app, whatever the
 *   list says. Data is untouched; switching it back restores everyone.
 * - Buka untuk semua ASN (stage 3): every ASN may use it, list or not.
 *
 * Every door picks a change up within about 90 seconds (KisaraAccess caches
 * the flags 30 s, callers cache answers 60 s).
 */
class Controls extends Component
{
    use WithSudo;

    public function toggleKillSwitch(string $app): void
    {
        $this->authorize('asn-portal.access.manage');
        abort_unless(KisaraAccess::knows($app), 404);

        $access = app(KisaraAccess::class);
        $turningOn = ! $access->flags($app)[KisaraAccess::FLAG_KILL_SWITCH];
        $label = KisaraAccess::apps()[$app]['label'];

        // Switching an app off for everyone is destructive (AGENTS §6).
        // Switching it back on is not, so only that direction needs sudo.
        if ($turningOn && ! $this->requireSudo('mematikan '.$label.' untuk semua pegawai')) {
            return;
        }

        $access->setFlag($app, KisaraAccess::FLAG_KILL_SWITCH, $turningOn, auth()->id());

        $this->dispatch('toast', ['type' => $turningOn ? 'warning' : 'success', 'message' => $turningOn
            ? $label.' dimatikan untuk semua pegawai. Datanya tidak tersentuh.'
            : $label.' dibuka kembali.']);
    }

    public function toggleOpen(string $app): void
    {
        $this->authorize('asn-portal.access.manage');
        abort_unless(KisaraAccess::knows($app), 404);

        $access = app(KisaraAccess::class);
        $opening = ! $access->flags($app)[KisaraAccess::FLAG_OPEN];
        $label = KisaraAccess::apps()[$app]['label'];

        // Closing back to the pilot list takes access away from everyone not
        // on it: as destructive as a kill switch for them.
        if (! $opening && ! $this->requireSudo('menutup '.$label.' kembali ke daftar pilot')) {
            return;
        }

        $access->setFlag($app, KisaraAccess::FLAG_OPEN, $opening, auth()->id());

        $this->dispatch('toast', ['type' => 'success', 'message' => $opening
            ? $label.' dibuka untuk semua ASN.'
            : $label.' kembali hanya untuk yang ada di daftar.']);
    }

    #[On('kisara-access-saved')]
    public function refreshCounts(): void
    {
        // Render reads fresh counts; nothing to reset.
    }

    public function render()
    {
        $access = app(KisaraAccess::class);
        $counts = AppAccess::query()->active()->selectRaw('app, COUNT(*) as total')->groupBy('app')->pluck('total', 'app');

        $cards = [];
        foreach (KisaraAccess::apps() as $key => $app) {
            $flags = $access->flags($key);
            $cards[$key] = $app + [
                'active' => (int) ($counts[$key] ?? 0),
                'kill_switch' => $flags[KisaraAccess::FLAG_KILL_SWITCH],
                'open_to_all' => $flags[KisaraAccess::FLAG_OPEN],
            ];
        }

        return view('nawasara-asn-portal::livewire.pages.access.section.controls', ['cards' => $cards]);
    }
}
