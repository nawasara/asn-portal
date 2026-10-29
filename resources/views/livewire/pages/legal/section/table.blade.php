<div>
    <x-nawasara-ui::page-header
        title="Dokumen Hukum"
        description="Kebijakan Privasi dan Persyaratan Layanan Portal ASN. Versi yang sudah diterbitkan tidak bisa diubah; perubahan dibuat sebagai versi baru."
        :count="count($this->rows)">
        @can('asn-portal.content.manage')
            <x-nawasara-ui::button color="primary" wire:click="create">
                Tulis Draf
            </x-nawasara-ui::button>
        @endcan
    </x-nawasara-ui::page-header>

    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel
                    label="Filter"
                    :state="['typeFilter' => $typeFilter, 'statusFilter' => $statusFilter]"
                    :labels="['typeFilter' => $typeLabels, 'statusFilter' => $statusLabels]">
                    <x-nawasara-ui::filter-group
                        label="Dokumen"
                        model="typeFilter"
                        :items="$typeLabels"
                        icon="lucide-file-text" />
                    <x-nawasara-ui::filter-group
                        label="Status"
                        model="statusFilter"
                        :items="$statusLabels"
                        icon="lucide-calendar-check" />
                </x-nawasara-ui::filter-panel>
            </div>
        </div>

        <div data-filter-chips class="flex flex-wrap items-center gap-2"></div>
    </div>

    @if (count($this->rows) === 0)
        @if ($typeFilter !== '' || $statusFilter !== '')
            <x-nawasara-ui::empty-state
                icon="lucide-search-x"
                title="Tidak ada dokumen yang cocok"
                description="Ubah saringannya." />
        @else
            <x-nawasara-ui::empty-state
                icon="lucide-scale"
                title="Belum ada dokumen"
                description="Tulis draf Kebijakan Privasi dan Persyaratan Layanan. Selama belum ada versi yang berlaku, portal tidak menampilkan dokumennya." />
        @endif
    @else
        <x-nawasara-ui::table :headers="['Dokumen', 'Versi', 'Status', 'Berlaku', 'Diterbitkan', '']" stickyLast>
            <x-slot:table>
            @foreach ($this->rows as $row)
                @php
                    $badge = match ($row->status) {
                        'current' => 'success',
                        'scheduled' => 'info',
                        'draft' => 'warning',
                        default => 'neutral',
                    };
                    $isDraft = $row->status === 'draft';
                @endphp
                <tr wire:key="legal-document-{{ $row->id }}">
                    <td class="px-6 py-4">
                        <span class="text-sm text-neutral-800 dark:text-neutral-100">{{ $row->title }}</span>
                        <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ $typeLabels[$row->type] ?? $row->type }}</p>
                    </td>
                    <td class="px-6 py-4 font-mono text-sm text-neutral-700 dark:text-neutral-200">{{ $row->version }}</td>
                    <td class="px-6 py-4">
                        <x-nawasara-ui::badge :color="$badge">{{ $statusLabels[$row->status] }}</x-nawasara-ui::badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-neutral-700 dark:text-neutral-200">
                        {{ $row->effective_at ? $row->effective_at->timezone($timezone)->translatedFormat('j M Y H:i').' WIB' : '—' }}
                    </td>
                    <td class="px-6 py-4 text-sm text-neutral-700 dark:text-neutral-200">
                        {{ $row->published_at ? $row->published_at->timezone($timezone)->translatedFormat('j M Y') : '—' }}
                    </td>
                    <td class="px-6 py-4 text-right">
                        @if ($isDraft)
                            <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="[
                                [
                                    'type' => 'click',
                                    'label' => 'Ubah',
                                    'wire:click' => 'edit('.$row->id.')',
                                    'modal' => 'legal-document-form',
                                    'icon' => 'lucide-pencil',
                                    'permission' => 'asn-portal.content.manage',
                                ],
                                [
                                    'type' => 'click',
                                    'label' => 'Terbitkan',
                                    'wire:click' => 'preparePublish('.$row->id.')',
                                    'modal' => 'legal-document-publish',
                                    'icon' => 'lucide-send',
                                    'permission' => 'asn-portal.legal.publish',
                                ],
                                [
                                    'type' => 'click',
                                    'label' => 'Hapus',
                                    'wire:click' => 'delete('.$row->id.')',
                                    'icon' => 'lucide-trash-2',
                                    'confirm' => 'Hapus draf ini secara permanen?',
                                    'permission' => 'asn-portal.content.manage',
                                ],
                            ]" />
                        @else
                            <x-nawasara-ui::dropdown-menu-action :id="$row->id" :items="[
                                [
                                    'type' => 'click',
                                    'label' => 'Lihat',
                                    'wire:click' => 'view('.$row->id.')',
                                    'modal' => 'legal-document-view',
                                    'icon' => 'lucide-eye',
                                    'permission' => 'asn-portal.content.view',
                                ],
                                [
                                    'type' => 'click',
                                    'label' => 'Jadikan draf versi baru',
                                    'wire:click' => 'duplicate('.$row->id.')',
                                    'modal' => 'legal-document-form',
                                    'icon' => 'lucide-copy',
                                    'permission' => 'asn-portal.content.manage',
                                ],
                            ]" />
                        @endif
                    </td>
                </tr>
            @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>
    @endif

    {{-- Draft form --}}
    <x-nawasara-ui::modal id="legal-document-form" :title="$editingId ? 'Ubah Draf' : 'Tulis Draf'">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-nawasara-ui::form.select label="Dokumen" wire:model="type" :options="$typeLabels" placeholder="Pilih dokumen" />
                </div>
                <div>
                    <x-nawasara-ui::form.input type="text" label="Versi" wire:model="version" placeholder="1.0" />
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.input type="text" label="Judul" wire:model="title" placeholder="Kebijakan Privasi Akun ASN" />
            </div>

            <div>
                <x-nawasara-ui::form.textarea
                    label="Isi"
                    wire:model="body"
                    :rows="14"
                    hint="Markdown. Judul bagian ditulis ## Judul; daftar dengan tanda -. HTML tidak ditampilkan." />
            </div>

            <div>
                <x-nawasara-ui::form.textarea
                    label="Ringkasan Perubahan"
                    wire:model="change_summary"
                    :rows="3"
                    hint="Untuk versi pengganti: ditampilkan kepada ASN saat versi ini diumumkan." />
            </div>
        </div>

        <x-slot name="footer">
            <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'legal-document-form')">
                Batal
            </x-nawasara-ui::button>
            <x-nawasara-ui::button color="primary" wire:click="save">
                Simpan Draf
            </x-nawasara-ui::button>
        </x-slot>
    </x-nawasara-ui::modal>

    {{-- Publish dialog --}}
    <x-nawasara-ui::modal id="legal-document-publish" title="Terbitkan Dokumen">
        @if ($this->publishing)
            <div class="space-y-4">
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    <span class="font-medium">{{ $this->publishing->title }}</span>
                    <span class="font-mono text-neutral-500 dark:text-neutral-400">v{{ $this->publishing->version }}</span>
                </p>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300">
                    Setelah diterbitkan, versi ini mengikat semua ASN dan <b>tidak bisa diubah atau dihapus</b>.
                    Perubahan berikutnya harus dibuat sebagai versi baru.
                </div>

                <div>
                    <x-nawasara-ui::form.input type="datetime-local" label="Berlaku Sejak (WIB)" wire:model="effectiveAtInput" />
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        Versi pertama boleh langsung berlaku. Versi pengganti paling cepat {{ \Nawasara\AsnPortal\Support\LegalDocumentSchedule::NOTICE_DAYS }} hari setelah diterbitkan, sesuai janji di dokumennya.
                    </p>
                    @error('effectiveAtInput')
                        <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endif

        <x-slot name="footer">
            <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'legal-document-publish')">
                Batal
            </x-nawasara-ui::button>
            <x-nawasara-ui::button color="primary" wire:click="publish">
                Terbitkan
            </x-nawasara-ui::button>
        </x-slot>
    </x-nawasara-ui::modal>

    {{-- Read-only view of a published version --}}
    <x-nawasara-ui::modal id="legal-document-view" :title="$this->viewing?->title ?? 'Dokumen'">
        @if ($this->viewing)
            <p class="mb-3 text-xs text-neutral-500 dark:text-neutral-400">
                Versi {{ $this->viewing->version }} · berlaku
                {{ $this->viewing->effective_at?->timezone($timezone)->translatedFormat('j F Y H:i') }} WIB
            </p>
            {{-- Shown as plain text on purpose: the panel never renders the
                 Markdown as HTML, so stored content cannot inject markup. --}}
            <pre class="max-h-[60vh] overflow-auto whitespace-pre-wrap rounded-lg bg-neutral-50 p-4 font-sans text-sm text-neutral-800 dark:bg-neutral-900 dark:text-neutral-200">{{ $this->viewing->body }}</pre>
        @endif
    </x-nawasara-ui::modal>
</div>
