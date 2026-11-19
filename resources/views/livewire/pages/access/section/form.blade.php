<div>
    <x-nawasara-ui::modal id="kisara-access-form" :title="$editingNip ? 'Ubah Akses Layanan' : 'Tambah Pegawai'" maxWidth="lg">
        <div class="space-y-4">
            {{-- 1. Pegawai: dipilih dari direktori Keycloak, tidak diketik. --}}
            <div>
                <x-nawasara-ui::form.label value="Pegawai" />
                @if ($picked)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2.5 dark:border-neutral-700">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $picked['name'] }}</span>
                            <span class="block font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $picked['nip'] }}</span>
                        </span>
                        @unless ($editingNip)
                            <x-nawasara-ui::button color="neutral" size="sm" wire:click="unpick">Ganti</x-nawasara-ui::button>
                        @endunless
                    </div>
                @else
                    <x-nawasara-ui::form.input
                        wire:model.live.debounce.400ms="userSearch"
                        placeholder="Ketik nama atau NIP (min. 2 huruf)..."
                        autocomplete="off" />
                    <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-neutral-700">
                        @forelse ($this->userResults as $idx => $u)
                            <button type="button" wire:key="kc-{{ $u['kc_id'] }}" wire:click="pick({{ $idx }})"
                                class="flex w-full items-center justify-between gap-3 border-b border-gray-100 px-3 py-2.5 text-left last:border-0 hover:bg-sky-50 dark:border-neutral-800 dark:hover:bg-sky-900/20">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $u['name'] }}</span>
                                    <span class="block truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $u['nip'] ? 'NIP '.$u['nip'] : $u['kc_username'] }}</span>
                                </span>
                                <span class="shrink-0 text-xs font-medium text-sky-600 dark:text-sky-400">Pilih</span>
                            </button>
                        @empty
                            <div class="px-3 py-5 text-center text-sm text-neutral-500 dark:text-neutral-400">
                                @if (mb_strlen(trim($userSearch)) < 2)
                                    Ketik minimal 2 huruf untuk mencari di direktori pegawai.
                                @else
                                    Tidak ada yang cocok, atau sudah ada di daftar.
                                @endif
                            </div>
                        @endforelse
                    </div>
                @endif
                @error('picked') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- 2. Layanan: satu baris per aplikasi dari config access.apps. --}}
            <div class="space-y-3">
                <x-nawasara-ui::form.label value="Layanan" />
                @foreach ($this->apps as $key => $app)
                    <div wire:key="access-form-{{ $key }}" class="rounded-lg border border-gray-200 p-3 dark:border-neutral-700">
                        <x-nawasara-ui::form.checkbox wire:model.live="access.{{ $key }}.on" :label="$app['label']" />
                        @if ($app['description'])
                            <p class="ml-6 text-xs text-neutral-500 dark:text-neutral-400">{{ $app['description'] }}</p>
                        @endif

                        @if ($access[$key]['on'] ?? false)
                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <x-nawasara-ui::form.select label="Tahap" wire:model="access.{{ $key }}.stage" :options="$this->stageOptions" />
                                    @error('access.'.$key.'.stage') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                                @if ($app['opd_override'] && $this->opdOptions !== [])
                                    <div>
                                        <x-nawasara-ui::form.select label="OPD (opsional)" wire:model="access.{{ $key }}.opd_id"
                                            placeholder="Menurut SIMAS Hebat" :options="$this->opdOptions" />
                                        @error('access.'.$key.'.opd_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>
                            @if ($app['opd_override'] && $this->opdOptions !== [])
                                <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                                    Biarkan OPD kosong supaya mengikuti SIMAS Hebat. Isi hanya bila data SIMAS belum benar.
                                </p>
                            @endif
                        @elseif ($editingNip)
                            <p class="ml-6 mt-1 text-xs text-neutral-500 dark:text-neutral-400">Tidak dicentang: tidak boleh memakai layanan ini. Akses yang ada dinonaktifkan, datanya tetap.</p>
                        @endif
                    </div>
                @endforeach
                @error('access') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-nawasara-ui::form.textarea label="Catatan (opsional)" wire:model="note" :rows="2"
                    placeholder="Mis. penguji fitur berbagi Folder OPD." />
                @error('note') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'kisara-access-form')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button color="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">Simpan</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
