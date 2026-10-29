<div>
    <x-nawasara-ui::page-header
        title="Bantuan"
        description="Pertanyaan umum dan jalan keluar 'Tidak bisa masuk?' di Portal ASN. Jawaban ditulis dalam Markdown."
        :count="count($this->rows)">
        @can('asn-portal.content.manage')
            <x-nawasara-ui::button color="primary" wire:click="create">
                Tambah Bantuan
            </x-nawasara-ui::button>
        @endcan
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel
                    label="Filter"
                    :state="['kindFilter' => $kindFilter, 'statusFilter' => $statusFilter]"
                    :labels="[
                        'kindFilter' => $kindLabels,
                        'statusFilter' => ['active' => 'Aktif', 'inactive' => 'Nonaktif'],
                    ]">
                    <x-nawasara-ui::filter-group
                        label="Jenis"
                        model="kindFilter"
                        :items="$kindLabels"
                        icon="lucide-tags" />
                    <x-nawasara-ui::filter-group
                        label="Status"
                        model="statusFilter"
                        :items="['active' => 'Aktif', 'inactive' => 'Nonaktif']"
                        icon="lucide-toggle-left" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari pertanyaan atau jawaban..." />
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if (count($this->rows) === 0)
        @if ($search !== '' || $kindFilter !== '' || $statusFilter !== '')
            <x-nawasara-ui::empty-state
                icon="lucide-search-x"
                title="Tidak ada bantuan yang cocok"
                description="Ubah kata kunci atau saringannya." />
        @else
            <x-nawasara-ui::empty-state
                icon="lucide-circle-help"
                title="Belum ada bantuan"
                description="Jalankan ContentSeeder untuk mengisi bantuan dari portal lama, atau tambahkan satu per satu." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Pertanyaan', 'Jenis', 'Tombol', 'Urutan', 'Status', '']" stickyLast>
            <x-slot:table>
            @foreach ($this->rows as $row)
                <tr wire:key="help-item-{{ $row->id }}">
                    <td class="px-6 py-4">
                        <span class="text-sm text-neutral-800 dark:text-neutral-100">{{ $row->question }}</span>
                        <p class="mt-0.5 line-clamp-2 max-w-xl text-xs text-neutral-500 dark:text-neutral-400">{{ $row->answer }}</p>
                    </td>
                    <td class="px-6 py-4 text-sm text-neutral-700 dark:text-neutral-200">
                        {{ $kindLabels[$row->kind] ?? $row->kind }}
                    </td>
                    <td class="px-6 py-4 text-sm text-neutral-700 dark:text-neutral-200">
                        {{ $row->action_label ?? '—' }}
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
                                'modal' => 'help-item-form',
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
                                'confirm' => 'Hapus bantuan ini secara permanen? Untuk menyembunyikan sementara, pakai Nonaktifkan.',
                                'permission' => 'asn-portal.content.manage',
                            ],
                        ]" />
                    </td>
                </tr>
            @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>
    @endif

    <x-nawasara-ui::modal id="help-item-form" :title="$editingId ? 'Ubah Bantuan' : 'Tambah Bantuan'">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.select label="Jenis" wire:model="kind" :options="$kindLabels" placeholder="Pilih jenis" />
                </div>
                <div>
                    <x-nawasara-ui::form.input type="text" label="Slug" wire:model="slug" placeholder="lupa-password" />
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.input type="text" label="Pertanyaan" wire:model="question" />
            </div>

            <div>
                <x-nawasara-ui::form.textarea
                    label="Jawaban"
                    wire:model="answer"
                    :rows="6"
                    hint="Markdown. Baris kosong memisahkan paragraf; tautan ditulis [teks](https://...). HTML tidak ditampilkan." />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.input type="text" label="Teks Tombol" wire:model="action_label" placeholder="Lupa kata sandi SimasHebat" />
                </div>
                <div>
                    <x-nawasara-ui::form.input type="text" label="Alamat Tombol" wire:model="action_url" placeholder="https://..." />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.input type="number" label="Urutan" wire:model="sort_order" />
                </div>
            </div>
        </div>

        <x-slot name="footer">
            <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'help-item-form')">
                Batal
            </x-nawasara-ui::button>
            <x-nawasara-ui::button color="primary" wire:click="save">
                Simpan
            </x-nawasara-ui::button>
        </x-slot>
    </x-nawasara-ui::modal>
</div>
