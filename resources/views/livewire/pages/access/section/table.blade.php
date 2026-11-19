<div>
    <x-nawasara-ui::page-header
        title="Akses Layanan Kisara"
        description="Pegawai yang boleh memakai layanan Kisara selama uji coba, per layanan. Menonaktifkan akses tidak menghapus data pegawai itu."
        :count="$this->totalPeople.' pegawai aktif'">
        @can('asn-portal.access.manage')
            <x-nawasara-ui::button color="primary"
                x-on:click="$dispatch('open-modal', { id: 'kisara-access-form', loading: true }); $dispatch('kisara-access-create')">
                Tambah Pegawai
            </x-nawasara-ui::button>
        @endcan
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel label="Filter"
                    :state="['appFilter' => $appFilter, 'statusFilter' => $statusFilter]"
                    :labels="['appFilter' => $this->appOptions, 'statusFilter' => $this->statusOptions]">
                    <x-nawasara-ui::filter-group label="Layanan" model="appFilter"
                        :items="$this->appOptions" icon="lucide-layout-grid" />
                    <x-nawasara-ui::filter-group label="Status" model="statusFilter"
                        :items="$this->statusOptions" icon="lucide-circle-dot" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari nama atau NIP..." />
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if ($this->people->total() === 0)
        @if ($search !== '' || $appFilter !== '' || $statusFilter !== '')
            <x-nawasara-ui::empty-state icon="lucide-search-x"
                title="Tidak ada yang cocok"
                description="Ubah kata kunci atau saringannya." />
        @else
            <x-nawasara-ui::empty-state icon="lucide-users"
                title="Belum ada pegawai"
                description="Tambahkan pegawai yang akan mencoba layanan Kisara, lalu pilih layanan yang boleh dipakainya." />
        @endif
    @else
        @php
            $headers = array_merge(['Pegawai'], array_column($this->apps, 'short'), ['Catatan', '']);
        @endphp
        <x-nawasara-ui::table :headers="$headers" stickyLast>
            <x-slot:table>
                @foreach ($this->people as $person)
                    @php
                        $rows = $this->rowsByPerson->get($person->nip, collect());
                        $items = [
                            ['type' => 'click', 'label' => 'Ubah akses', 'wire:click' => "edit('".$person->nip."')",
                             'modal' => 'kisara-access-form', 'icon' => 'lucide-pencil', 'permission' => 'asn-portal.access.manage'],
                        ];
                        foreach ($this->apps as $key => $app) {
                            $row = $rows->get($key);
                            if (! $row) {
                                continue;
                            }
                            $items[] = $row->isActive()
                                ? ['type' => 'click', 'label' => 'Nonaktifkan '.$app['short'], 'wire:click' => 'deactivate('.$row->id.')', 'icon' => 'lucide-user-x',
                                   'confirm' => 'Nonaktifkan akses '.$app['label'].' untuk pegawai ini? Datanya tidak dihapus dan aksesnya bisa diaktifkan lagi.',
                                   'permission' => 'asn-portal.access.manage']
                                : ['type' => 'click', 'label' => 'Aktifkan '.$app['short'], 'wire:click' => 'activate('.$row->id.')', 'icon' => 'lucide-user-check',
                                   'permission' => 'asn-portal.access.manage'];
                        }
                        $items[] = ['type' => 'click', 'label' => 'Hapus', 'wire:click' => "delete('".$person->nip."')", 'icon' => 'lucide-trash-2',
                            'confirm' => 'Hapus pegawai ini dari daftar akses semua layanan? Pakai ini hanya untuk salah input; untuk menghentikan akses pakai Nonaktifkan.',
                            'permission' => 'asn-portal.access.manage'];
                        $note = $rows->pluck('note')->filter()->unique()->implode(' / ');
                    @endphp
                    <tr wire:key="access-{{ $person->nip }}">
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $person->person_name }}</div>
                            <div class="font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $person->nip }}</div>
                        </td>
                        @foreach ($this->apps as $key => $app)
                            @php $row = $rows->get($key); @endphp
                            <td class="px-4 py-3 text-sm">
                                @if (! $row)
                                    <span class="text-neutral-400 dark:text-neutral-500">Tidak ada</span>
                                @elseif ($row->isActive())
                                    <x-nawasara-ui::badge color="success">Aktif</x-nawasara-ui::badge>
                                    <div class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ $stages[$row->stage] ?? 'Tahap '.$row->stage }}</div>
                                @else
                                    <x-nawasara-ui::badge color="neutral">Nonaktif</x-nawasara-ui::badge>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $note !== '' ? $note : '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            <x-nawasara-ui::dropdown-menu-action :id="'access-'.$person->nip" :items="$items" />
                        </td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>

        <div class="mt-4">{{ $this->people->links() }}</div>
    @endif
</div>
