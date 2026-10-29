<?php

namespace Nawasara\AsnPortal\Livewire\App\Section;

use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\AsnPortal\Models\PortalApp;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;

/**
 * Manage the app catalog shown on the portal's guest page and used to name
 * apps in "Aplikasi terhubung".
 */
class Table extends Component
{
    use WithSudo;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    // Set by the server only (edit); locked so a crafted wire payload cannot
    // redirect a save onto another row.
    #[Locked]
    public ?int $editingId = null;

    public string $slug = '';
    public string $name = '';
    public ?string $description = null;
    public string $url = '';
    public ?string $keycloak_client_id = null;
    public ?string $category = null;
    public ?string $abbreviation = null;
    public ?string $color = null;
    public ?string $icon_url = null;
    public int $sort_order = 0;

    #[Computed]
    public function rows()
    {
        return PortalApp::query()
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($w) => $w
                    ->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('keycloak_client_id', 'like', $term)
                    ->orWhere('url', 'like', $term));
            })
            ->when($this->statusFilter !== '', fn ($q) => $q->where('is_active', $this->statusFilter === 'active'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('asn-portal.content.manage');

        $this->resetForm();
        $this->sort_order = ((int) PortalApp::max('sort_order')) + 10;

        $this->dispatch('modal-open:portal-app-form');
    }

    public function edit(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        $app = PortalApp::findOrFail($id);

        $this->resetForm();
        $this->editingId = $app->id;
        $this->fill($app->only([
            'slug', 'name', 'description', 'url', 'keycloak_client_id', 'category',
            'abbreviation', 'color', 'icon_url', 'sort_order',
        ]));

        $this->dispatch('modal-open:portal-app-form');
    }

    public function save(): void
    {
        $this->authorize('asn-portal.content.manage');

        foreach (['description', 'keycloak_client_id', 'category', 'abbreviation', 'color', 'icon_url'] as $field) {
            $this->{$field} = $this->{$field} === null || trim($this->{$field}) === '' ? null : trim($this->{$field});
        }

        $data = $this->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('nawasara_asn_portal_apps', 'slug')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // https only: this link is shown to every civil servant as the
            // way into an official app.
            'url' => ['required', 'url:https', 'max:500'],
            'keycloak_client_id' => ['nullable', 'string', 'max:120',
                Rule::unique('nawasara_asn_portal_apps', 'keycloak_client_id')->ignore($this->editingId)],
            'category' => ['nullable', 'string', 'max:60'],
            'abbreviation' => ['nullable', 'string', 'max:4'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon_url' => ['nullable', 'url:https', 'max:500'],
            'sort_order' => ['integer', 'min:0', 'max:99999'],
        ], [
            'slug.regex' => 'Slug hanya huruf kecil, angka, dan tanda hubung, mis. satu-data.',
            'url.url' => 'Alamat harus diawali https://.',
            'icon_url.url' => 'Alamat ikon harus diawali https://.',
            'color.regex' => 'Warna ditulis sebagai kode hex enam digit, mis. #0f8b8d.',
        ]);

        if ($this->editingId) {
            PortalApp::findOrFail($this->editingId)->update($data);
        } else {
            PortalApp::create($data + ['is_active' => true]);
        }

        $this->dispatch('modal-close:portal-app-form');
        $this->dispatch('toast', type: 'success', message: 'Aplikasi tersimpan.');

        unset($this->rows);
    }

    /** Hide from the portal without losing the entry or its history. */
    public function toggle(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        $app = PortalApp::findOrFail($id);
        $app->update(['is_active' => ! $app->is_active]);

        unset($this->rows);
    }

    /**
     * Permanent delete, for entries that were wrong (typo, duplicate).
     * A retired app should be deactivated instead, so its row still names
     * old sessions in "Aplikasi terhubung".
     */
    #[RequiresSudo(reason: 'menghapus aplikasi dari katalog Portal ASN')]
    public function delete(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        PortalApp::findOrFail($id)->delete();

        $this->dispatch('toast', type: 'success', message: 'Aplikasi dihapus.');

        unset($this->rows);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'slug', 'name', 'description', 'url', 'keycloak_client_id', 'category',
            'abbreviation', 'color', 'icon_url', 'sort_order',
        ]);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.app.section.table');
    }
}
