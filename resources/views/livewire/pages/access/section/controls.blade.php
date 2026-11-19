<div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
    @foreach ($cards as $key => $card)
        <div wire:key="access-app-{{ $key }}"
            @class([
                'flex flex-col gap-3 rounded-lg border p-4',
                'border-red-300 bg-red-50 dark:border-red-700/50 dark:bg-red-900/30' => $card['kill_switch'],
                'border-gray-200 bg-white dark:border-neutral-700 dark:bg-neutral-800' => ! $card['kill_switch'],
            ])>
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $card['label'] }}</p>
                    @if ($card['description'])
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $card['description'] }}</p>
                    @endif
                </div>
                @if ($card['kill_switch'])
                    <x-nawasara-ui::badge color="danger">Dimatikan</x-nawasara-ui::badge>
                @elseif ($card['open_to_all'])
                    <x-nawasara-ui::badge color="success">Semua ASN</x-nawasara-ui::badge>
                @else
                    <x-nawasara-ui::badge color="info">Pilot</x-nawasara-ui::badge>
                @endif
            </div>

            <p @class([
                'text-sm',
                'text-red-700 dark:text-red-300' => $card['kill_switch'],
                'text-neutral-600 dark:text-neutral-400' => ! $card['kill_switch'],
            ])>
                @if ($card['kill_switch'])
                    Tidak ada yang dapat memakai {{ $card['short'] }}, termasuk yang ada di daftar. Datanya tidak tersentuh.
                @elseif ($card['open_to_all'])
                    Terbuka untuk semua ASN (tahap 3). Daftar di bawah tetap disimpan.
                @else
                    {{ $card['active'] }} pegawai aktif di daftar. Hanya mereka yang dapat masuk.
                @endif
            </p>

            @can('asn-portal.access.manage')
                <div class="flex flex-wrap gap-2">
                    @if ($card['kill_switch'])
                        <x-nawasara-ui::button color="success" size="sm" wire:click="toggleKillSwitch('{{ $key }}')">Buka kembali</x-nawasara-ui::button>
                    @else
                        <x-nawasara-ui::button color="danger" size="sm" wire:click="toggleKillSwitch('{{ $key }}')"
                            wire:confirm="Matikan {{ $card['label'] }} untuk SEMUA pegawai? Tidak ada yang bisa masuk sampai dibuka kembali. Data tidak dihapus.">
                            Matikan untuk semua
                        </x-nawasara-ui::button>
                        @if ($card['open_to_all'])
                            <x-nawasara-ui::button color="neutral" size="sm" wire:click="toggleOpen('{{ $key }}')"
                                wire:confirm="Tutup {{ $card['label'] }} kembali hanya untuk yang ada di daftar? Pegawai lain tidak dapat masuk lagi.">
                                Kembali ke daftar pilot
                            </x-nawasara-ui::button>
                        @else
                            <x-nawasara-ui::button color="neutral" size="sm" wire:click="toggleOpen('{{ $key }}')"
                                wire:confirm="Buka {{ $card['label'] }} untuk SEMUA ASN, tanpa melihat daftar?">
                                Buka untuk semua ASN
                            </x-nawasara-ui::button>
                        @endif
                    @endif
                </div>
            @endcan
        </div>
    @endforeach
</div>
