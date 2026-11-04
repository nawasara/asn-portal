<?php

namespace Nawasara\AsnPortal\Livewire\App\Section;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Nawasara\AsnPortal\Models\PortalApp;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;

/**
 * Manage the app catalog shown on the portal's guest page and used to name
 * apps in "Aplikasi terhubung".
 */
class Table extends Component
{
    use WithFileUploads;
    use WithSudo;

    /** Uploaded icons live on the public disk, so the portals can load them directly. */
    public const ICON_DIR = 'asn-portal/icons';

    // No SVG: an uploaded SVG is served from the panel's own origin and can
    // carry script. Raster only.
    private const ICON_RULES = ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512',
        'dimensions:min_width=64,min_height=64,max_width=1024,max_height=1024'];

    private const ICON_MESSAGES = [
        'icon.image' => 'Ikon harus berupa gambar.',
        'icon.mimes' => 'Ikon harus PNG, JPG, atau WebP.',
        'icon.max' => 'Ukuran ikon paling besar 512 KB.',
        'icon.dimensions' => 'Ikon berukuran 64 sampai 1024 piksel per sisi.',
    ];

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

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $icon = null;

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
            // An icon we stored ourselves carries APP_URL, which is http on a
            // laptop; only addresses typed by staff must be https.
            'icon_url' => self::storedIconPath($this->icon_url) !== null
                ? ['nullable', 'string', 'max:500']
                : ['nullable', 'url:https', 'max:500'],
            'icon' => self::ICON_RULES,
            'sort_order' => ['integer', 'min:0', 'max:99999'],
        ], [
            'slug.regex' => 'Slug hanya huruf kecil, angka, dan tanda hubung, mis. satu-data.',
            'url.url' => 'Alamat harus diawali https://.',
            'icon_url.url' => 'Alamat ikon harus diawali https://.',
            'color.regex' => 'Warna ditulis sebagai kode hex enam digit, mis. #0f8b8d.',
        ] + self::ICON_MESSAGES);
        unset($data['icon']);

        if ($this->icon) {
            // Random suffix: a replaced icon gets a new address, so portals
            // that cache the catalog never keep showing the old picture.
            $path = $this->icon->storeAs(
                self::ICON_DIR,
                $data['slug'].'-'.Str::lower(Str::random(8)).'.'.$this->icon->extension(),
                'public',
            );
            $data['icon_url'] = self::iconUrl($path);
        }

        $previous = $this->editingId ? PortalApp::findOrFail($this->editingId)->icon_url : null;

        if ($this->editingId) {
            PortalApp::findOrFail($this->editingId)->update($data);
        } else {
            PortalApp::create($data + ['is_active' => true]);
        }

        if ($previous !== $data['icon_url']) {
            self::deleteStoredIcon($previous);
        }

        $this->icon = null;
        $this->icon_url = $data['icon_url'];

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

        $app = PortalApp::findOrFail($id);
        $app->delete();
        self::deleteStoredIcon($app->icon_url);

        $this->dispatch('toast', type: 'success', message: 'Aplikasi dihapus.');

        unset($this->rows);
    }

    /** Check the file as soon as it is picked, so a wrong one is named before Simpan. */
    public function updatedIcon(): void
    {
        $this->validateOnly('icon', ['icon' => self::ICON_RULES], self::ICON_MESSAGES);
    }

    /** Clear the icon in the form; the stored file goes when the form is saved. */
    public function removeIcon(): void
    {
        $this->icon = null;
        $this->icon_url = null;
        $this->resetErrorBag(['icon', 'icon_url']);
    }

    /**
     * Path on the public disk when $url is an icon this page uploaded, null
     * for anything else (an address typed by staff is never ours to delete).
     */
    public static function storedIconPath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // url() drops a trailing slash, so add it back after.
        $prefix = rtrim(self::iconUrl(self::ICON_DIR), '/').'/';
        if (! str_starts_with($url, $prefix)) {
            return null;
        }

        $name = substr($url, strlen($prefix));

        // One plain file name, nothing that climbs out of the directory.
        return preg_match('/^[a-z0-9-]+\.(png|jpe?g|webp)$/', $name) === 1
            ? self::ICON_DIR.'/'.$name
            : null;
    }

    /**
     * Always absolute: the portals load the icon from their own domains, and
     * the disk URL is relative when the public disk has no `url` configured.
     */
    private static function iconUrl(string $path): string
    {
        return url(Storage::disk('public')->url($path));
    }

    private static function deleteStoredIcon(?string $url): void
    {
        if ($path = self::storedIconPath($url)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'slug', 'name', 'description', 'url', 'keycloak_client_id', 'category',
            'abbreviation', 'color', 'icon_url', 'sort_order', 'icon',
        ]);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.app.section.table');
    }
}
