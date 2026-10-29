<div>
    <x-nawasara-ui::page-header
        title="Katalog Aplikasi"
        description="Aplikasi yang tampil di Portal ASN. Perubahan langsung berlaku, tidak menunggu rilis portal."
        :count="count($this->rows)">
        @can('asn-portal.content.manage')
            <x-nawasara-ui::button color="primary" wire:click="create">
                Tambah Aplikasi
            </x-nawasara-ui::button>
        @endcan
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel
                    label="Filter"
                    :state="['statusFilter' => $statusFilter]"
                    :labels="['statusFilter' => ['active' => 'Aktif', 'inactive' => 'Nonaktif']]">
                    <x-nawasara-ui::filter-group
                        label="Status"
                        model="statusFilter"
                        :items="['active' => 'Aktif', 'inactive' => 'Nonaktif']"
                        icon="lucide-toggle-left" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input
                model="search"
                placeholder="Cari nama, keterangan, alamat, atau client Keycloak..." />
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if (count($this->rows) === 0)
        @if ($search !== '' || $statusFilter !== '')
            <x-nawasara-ui::empty-state
                icon="lucide-search-x"
                title="Tidak ada aplikasi yang cocok"
                description="Ubah kata kunci atau saringannya." />
        @else
            <x-nawasara-ui::empty-state
                icon="lucide-layout-grid"
                title="Belum ada aplikasi"
                description="Jalankan ContentSeeder untuk mengisi aplikasi dari portal lama, atau tambahkan satu per satu." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Aplikasi', 'Client Keycloak', 'Kategori', 'Urutan', 'Status', '']" stickyLast>
            <x-slot:table>
            @foreach ($this->rows as $row)
                <tr wire:key="portal-app-{{ $row->id }}">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-xs font-semibold text-white"
                                  style="background: {{ $row->color ?? '#475569' }}">{{ $row->abbreviation ?? mb_substr($row->name, 0, 2) }}</span>
                            <div class="min-w-0">
                                <span class="text-sm text-neutral-800 dark:text-neutral-100">{{ $row->name }}</span>
                                <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $row->url }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 font-mono text-xs text-neutral-700 dark:text-neutral-300">
                        {{ $row->keycloak_client_id ?? '—' }}
                    </td>
                    <td class="px-6 py-4 text-sm text-neutral-700 dark:text-neutral-200">
                        {{ $row->category ?? '—' }}
                    </td>
                    <td class="px-6 py-4 text-sm text-neutral-700 dark:text-neutral-200">
                        {{ $row->sort_order }}
                    </td>
                    <td class="px-6 py-4">
                        <x-nawasara-ui::badge :color="$row->is_active ? 'success' : 'neutral'">
                            {{ $row->is_active ? 'Aktif' : 'Nonaktif' }}
                        </x-nawasara-ui::badge>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="[
                            [
                                'type' => 'click',
                                'label' => 'Ubah',
                                'wire:click' => 'edit('.$row->id.')',
                                'modal' => 'portal-app-form',
                                'icon' => 'lucide-pencil',
                                'permission' => 'asn-portal.content.manage',
                            ],
                            [
                                'type' => 'click',
                                'label' => $row->is_active ? 'Nonaktifkan' : 'Aktifkan',
                                'wire:click' => 'toggle('.$row->id.')',
                                'icon' => $row->is_active ? 'lucide-eye-off' : 'lucide-eye',
                                'permission' => 'asn-portal.content.manage',
                            ],
                            [
                                'type' => 'click',
                                'label' => 'Hapus',
                                'wire:click' => 'delete('.$row->id.')',
                                'icon' => 'lucide-trash-2',
                                'confirm' => 'Hapus ‘'.$row->name.'’ dari katalog secara permanen? Untuk menyembunyikan sementara, pakai Nonaktifkan.',
                                'permission' => 'asn-portal.content.manage',
                            ],
                        ]" />
                    </td>
                </tr>
            @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>
    @endif

    <x-nawasara-ui::modal id="portal-app-form" :title="$editingId ? 'Ubah Aplikasi' : 'Tambah Aplikasi'">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.input type="text" label="Nama" wire:model="name" placeholder="Satu Data Ponorogo" />
                </div>
                <div>
                    <x-nawasara-ui::form.input type="text" label="Slug" wire:model="slug" placeholder="satu-data" />
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Penanda tetap untuk portal. Huruf kecil dan tanda hubung.</p>
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.input type="text" label="Alamat" wire:model="url" placeholder="https://sadap.ponorogo.go.id/sso" />
            </div>

            <div>
                <x-nawasara-ui::form.textarea label="Keterangan" wire:model="description" :rows="2" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.input type="text" label="Client Keycloak" wire:model="keycloak_client_id" placeholder="sadap" />
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Client id di realm asn-productions, untuk menamai sesi di Aplikasi terhubung. Kosongkan bila aplikasi belum memakai SSO.</p>
                </div>
                <div>
                    <x-nawasara-ui::form.input type="text" label="Kategori" wire:model="category" placeholder="Data" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <x-nawasara-ui::form.input type="text" label="Singkatan" wire:model="abbreviation" placeholder="SD" />
                </div>
                <div>
                    <x-nawasara-ui::form.input type="text" label="Warna" wire:model="color" placeholder="#0f8b8d" />
                </div>
                <div>
                    <x-nawasara-ui::form.input type="number" label="Urutan" wire:model="sort_order" />
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.input type="text" label="Alamat Ikon" wire:model="icon_url" placeholder="https://..." />
                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Opsional. Tanpa ikon, portal menampilkan singkatan di atas warna.</p>
            </div>
        </div>

        <x-slot name="footer">
            <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'portal-app-form')">
                Batal
            </x-nawasara-ui::button>
            {{-- wire:click, not submit: the modal renders the footer OUTSIDE
                 its content div, so a submit button would escape the form. --}}
            <x-nawasara-ui::button color="primary" wire:click="save">
                Simpan
            </x-nawasara-ui::button>
        </x-slot>
    </x-nawasara-ui::modal>
</div>
