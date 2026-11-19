<?php

namespace Nawasara\AsnPortal\Livewire\Access\Section;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Nawasara\AsnPortal\Models\AppAccess;
use Nawasara\AsnPortal\Services\KisaraAccess;
use Nawasara\Keycloak\Concerns\SearchesKeycloakDirectory;

/**
 * Add a person, or change which Kisara apps one person may use.
 *
 * The person is picked from the Keycloak directory, never typed: a NIP typed
 * by hand can belong to nobody, or to someone else. Picking is by index into
 * results recomputed on the server (SearchesKeycloakDirectory), so the DOM is
 * never trusted with an identity.
 *
 * Per app: a switch and a stage. Switching an app off here is "Nonaktifkan"
 * (removed_at, row kept), never a delete; deleting is a separate,
 * sudo-gated action in the table.
 */
class Form extends Component
{
    use SearchesKeycloakDirectory;

    /** Set by the server only (edit); locked so a crafted payload cannot retarget a save. */
    #[Locked]
    public ?string $editingNip = null;

    public string $userSearch = '';

    #[Locked]
    public ?array $picked = null;

    /** @var array<string, array{on: bool, stage: int|string, opd_id: int|string|null}> */
    public array $access = [];

    public ?string $note = null;

    #[Computed]
    public function apps(): array
    {
        return KisaraAccess::apps();
    }

    #[Computed]
    public function stageOptions(): array
    {
        return collect(KisaraAccess::stages())->mapWithKeys(fn ($label, $k) => [(string) $k => $label])->all();
    }

    /**
     * OPD list for the override field. From nawasara/registry when it is
     * installed (it is not a dependency of this package); without it the
     * field is not shown at all.
     */
    #[Computed]
    public function opdOptions(): array
    {
        if (! Schema::hasTable('nawasara_registry_opd')) {
            return [];
        }

        return DB::table('nawasara_registry_opd')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Directory results, without people who are already in the list. */
    #[Computed]
    public function userResults(): array
    {
        return $this->keycloakSearchResults($this->userSearch, $this->existingNips());
    }

    #[On('kisara-access-create')]
    public function create(): void
    {
        $this->authorize('asn-portal.access.manage');
        $this->resetForm();
        $this->dispatch('modal-open:kisara-access-form');
    }

    #[On('kisara-access-edit')]
    public function edit(string $nip): void
    {
        $this->authorize('asn-portal.access.manage');
        $this->resetForm();

        $rows = AppAccess::where('nip', $nip)->get()->keyBy('app');
        abort_if($rows->isEmpty(), 404);

        $first = $rows->first();
        $this->editingNip = $nip;
        $this->picked = ['nip' => $nip, 'name' => $first->name, 'kc_id' => $rows->pluck('keycloak_id')->filter()->first()];
        $this->note = $rows->pluck('note')->filter()->first();

        foreach ($this->apps as $key => $app) {
            $row = $rows->get($key);
            $this->access[$key] = [
                'on' => $row !== null && $row->isActive(),
                'stage' => (string) ($row?->stage ?? 1),
                'opd_id' => $row?->opd_id !== null ? (string) $row->opd_id : null,
            ];
        }

        $this->dispatch('modal-open:kisara-access-form');
    }

    public function pick(int $index): void
    {
        $this->authorize('asn-portal.access.manage');

        $kc = $this->keycloakUserAt($index, $this->userSearch, $this->existingNips());
        $nip = (string) ($kc?->nip ?: $kc?->username);

        if (! $kc || preg_match('/^\d{18}$/', $nip) !== 1) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Hanya akun ASN ber-NIP yang dapat diberi akses.']);

            return;
        }

        $this->picked = ['nip' => $nip, 'name' => $kc->full_name ?: $nip, 'kc_id' => $kc->user_id];
        $this->userSearch = '';
    }

    public function unpick(): void
    {
        if ($this->editingNip === null) {
            $this->picked = null;
        }
    }

    public function save(): void
    {
        $this->authorize('asn-portal.access.manage');

        $apps = $this->apps;
        $rules = ['note' => ['nullable', 'string', 'max:500']];
        $names = ['note' => 'catatan'];
        foreach ($apps as $key => $app) {
            $rules["access.{$key}.on"] = ['boolean'];
            $rules["access.{$key}.stage"] = ['required', 'integer', 'in:'.implode(',', array_keys(KisaraAccess::stages()))];
            $rules["access.{$key}.opd_id"] = $app['opd_override'] && $this->opdOptions !== []
                ? ['nullable', 'integer', 'exists:nawasara_registry_opd,id']
                : ['nullable'];
            $names["access.{$key}.stage"] = 'tahap '.$app['short'];
            $names["access.{$key}.opd_id"] = 'OPD';
        }
        $this->validate($rules, [], $names);

        if (! $this->picked) {
            $this->addError('picked', 'Pilih pegawainya dulu.');

            return;
        }

        $nip = $this->picked['nip'];
        $existing = AppAccess::where('nip', $nip)->get()->keyBy('app');

        if ($this->editingNip === null && $existing->isNotEmpty()) {
            // Fail-safe on top of the exclude list: another admin may have
            // added the same person between search and click.
            $this->addError('picked', 'Pegawai ini sudah ada di daftar. Ubah aksesnya dari tabel.');

            return;
        }

        $wanted = collect($apps)->keys()->filter(fn ($key) => (bool) ($this->access[$key]['on'] ?? false));
        if ($this->editingNip === null && $wanted->isEmpty()) {
            $this->addError('access', 'Pilih minimal satu layanan.');

            return;
        }

        $note = $this->note !== null && trim($this->note) !== '' ? trim($this->note) : null;

        DB::transaction(function () use ($apps, $existing, $nip, $note) {
            foreach ($apps as $key => $app) {
                $on = (bool) ($this->access[$key]['on'] ?? false);
                $row = $existing->get($key);
                $data = [
                    'stage' => (int) ($this->access[$key]['stage'] ?? 1),
                    'opd_id' => $app['opd_override'] && ($this->access[$key]['opd_id'] ?? null) !== null && $this->access[$key]['opd_id'] !== ''
                        ? (int) $this->access[$key]['opd_id'] : null,
                    'note' => $note,
                ];

                if ($on && ! $row) {
                    AppAccess::create($data + [
                        'nip' => $nip,
                        'app' => $key,
                        'name' => $this->picked['name'],
                        'keycloak_id' => $this->picked['kc_id'],
                        'added_by' => auth()->id(),
                    ]);
                } elseif ($on) {
                    $row->update($data + ['removed_at' => null, 'removed_by' => null]);
                } elseif ($row && $row->isActive()) {
                    $row->update($data + ['removed_at' => now(), 'removed_by' => auth()->id()]);
                } elseif ($row) {
                    $row->update($data);
                }
            }
        });

        $this->dispatch('modal-close:kisara-access-form');
        $this->dispatch('kisara-access-saved');
        $this->dispatch('toast', ['type' => 'success', 'message' => $this->editingNip
            ? 'Akses diperbarui. Berlaku paling lambat 1 menit.'
            : 'Pegawai ditambahkan. Akses aktif paling lambat 1 menit.']);
        $this->resetForm();
    }

    /** @return array<int, string> lowercase NIPs already in the list */
    private function existingNips(): array
    {
        return AppAccess::query()->distinct()->pluck('nip')->map(fn ($n) => mb_strtolower($n))->all();
    }

    private function resetForm(): void
    {
        $this->reset(['editingNip', 'userSearch', 'picked', 'note']);
        $this->access = [];
        foreach (KisaraAccess::apps() as $key => $app) {
            $this->access[$key] = ['on' => false, 'stage' => '1', 'opd_id' => null];
        }
        $this->resetValidation();
    }

    public function mount(): void
    {
        $this->resetForm();
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.access.section.form');
    }
}
