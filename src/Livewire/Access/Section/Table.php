<?php

namespace Nawasara\AsnPortal\Livewire\Access\Section;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\AsnPortal\Models\AppAccess;
use Nawasara\AsnPortal\Services\KisaraAccess;
use Nawasara\AuthPrimitives\Traits\WithSudo;

/**
 * The people list of Akses Layanan Kisara (AGENTS §1a): filter + search
 * toolbar, three-dot row actions, two empty states, and both "Nonaktifkan"
 * (per app, row and history kept, can be switched back) and "Hapus" (a
 * wrong entry: every row of that person, sudo).
 *
 * One table row per PERSON, one column per app, although the database holds
 * one row per person per app: the admin thinks "what may Pringgo use", not
 * "which rows does the mail list have".
 */
class Table extends Component
{
    use WithPagination, WithSudo;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'app', except: '')]
    public string $appFilter = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'appFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function apps(): array
    {
        return KisaraAccess::apps();
    }

    #[Computed]
    public function appOptions(): array
    {
        return collect($this->apps)->map(fn (array $app) => $app['label'])->all();
    }

    #[Computed]
    public function statusOptions(): array
    {
        return ['active' => 'Aktif', 'removed' => 'Nonaktif'];
    }

    /** People (nip + name) on this page, filtered. */
    #[Computed]
    public function people()
    {
        $app = KisaraAccess::knows($this->appFilter) ? $this->appFilter : null;
        $term = trim($this->search);

        return AppAccess::query()
            ->when($app, fn ($q) => $q->where('app', $app))
            ->when($this->statusFilter === 'active', fn ($q) => $q->whereNull('removed_at'))
            ->when($this->statusFilter === 'removed', fn ($q) => $q->whereNotNull('removed_at'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nip', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')))
            ->select('nip')
            ->selectRaw('MAX(name) as person_name')
            ->groupBy('nip')
            ->orderBy('person_name')
            ->orderBy('nip')
            ->paginate(25);
    }

    /** @return Collection<string, Collection<string, AppAccess>> nip => app => row */
    #[Computed]
    public function rowsByPerson(): Collection
    {
        $nips = collect($this->people->items())->pluck('nip')->all();

        return AppAccess::query()->whereIn('nip', $nips)->get()
            ->groupBy('nip')
            ->map(fn (Collection $rows) => $rows->keyBy('app'));
    }

    #[Computed]
    public function totalPeople(): int
    {
        return AppAccess::query()->active()->distinct()->count('nip');
    }

    public function edit(string $nip): void
    {
        $this->authorize('asn-portal.access.manage');
        $this->dispatch('kisara-access-edit', nip: $nip);
    }

    /** Stop one app for one person. Row, history and the person's data stay. */
    public function deactivate(int $id): void
    {
        $this->authorize('asn-portal.access.manage');

        $row = AppAccess::findOrFail($id);
        $row->update(['removed_at' => now(), 'removed_by' => auth()->id()]);
        $this->afterChange('Akses '.$this->label($row->app).' dinonaktifkan. Datanya tidak tersentuh.');
    }

    public function activate(int $id): void
    {
        $this->authorize('asn-portal.access.manage');

        $row = AppAccess::findOrFail($id);
        $row->update(['removed_at' => null, 'removed_by' => null]);
        $this->afterChange('Akses '.$this->label($row->app).' diaktifkan kembali.');
    }

    /** Delete a wrong entry: every app row of this person. Sudo-gated (AGENTS §6). */
    public function delete(string $nip): void
    {
        $this->authorize('asn-portal.access.manage');

        if (! $this->requireSudo('menghapus pegawai dari Akses Layanan Kisara')) {
            return;
        }

        // One by one, not a mass delete: each row fires its activity log
        // entry and the change event its app listens to.
        AppAccess::where('nip', $nip)->get()->each->delete();
        $this->afterChange('Pegawai dihapus dari daftar akses.');
    }

    #[On('kisara-access-saved')]
    public function refreshRows(): void
    {
        unset($this->people, $this->rowsByPerson, $this->totalPeople);
    }

    private function afterChange(string $message): void
    {
        $this->refreshRows();
        $this->dispatch('kisara-access-saved');
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    private function label(string $app): string
    {
        return KisaraAccess::apps()[$app]['short'] ?? $app;
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.access.section.table', [
            'stages' => KisaraAccess::stages(),
        ]);
    }
}
