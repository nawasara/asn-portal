@php
    $rows = $this->rows;
    $current = $rows[0] ?? null;
    $total = fn (string $k) => array_sum(array_column($rows, $k));
    $empty = $total('roundcube_opens') + $total('kisara_opens') === 0;
@endphp
<div>
    <x-nawasara-ui::page-header title="Pemakaian Email"
        description="Pembukaan mailbox per minggu: webmail lama (Roundcube lewat Akun Kisara) dibanding Kisara Mail. Minggu dihitung Senin–Minggu WIB.">
        <x-nawasara-ui::form.select wire:model.live="weeks" :placeholder="false" :options="[4 => '4 minggu', 12 => '12 minggu', 26 => '26 minggu', 52 => '52 minggu']" />
        <x-nawasara-ui::icon-button icon="refresh-cw" tooltip="Muat ulang" wire:click="refreshData" placement="left" />
    </x-nawasara-ui::page-header>

    @if ($current)
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            <x-nawasara-ui::stat-card compact label="Roundcube minggu ini" :value="$current['roundcube_opens']" color="neutral" icon="lucide-mail" />
            <x-nawasara-ui::stat-card compact label="ASN Roundcube" :value="$current['roundcube_users']" color="neutral" icon="lucide-users" />
            <x-nawasara-ui::stat-card compact label="Kisara Mail minggu ini" :value="$current['kisara_opens']" color="primary" icon="lucide-mail-open" />
            <x-nawasara-ui::stat-card compact label="ASN Kisara Mail" :value="$current['kisara_users']" color="primary" icon="lucide-users" />
        </div>
    @endif

    @if ($empty)
        <x-nawasara-ui::empty-state icon="lucide-inbox" title="Belum ada pembukaan mailbox"
            description="Angka muncul setelah ASN membuka mailbox lewat Akun Kisara atau Kisara Mail." />
    @else
        <x-nawasara-ui::table :headers="['Minggu mulai', 'Roundcube', 'ASN unik', 'Kisara Mail', 'ASN unik', 'Porsi Kisara Mail']">
            <x-slot:table>
                @foreach ($rows as $row)
                    @php
                        $all = $row['roundcube_opens'] + $row['kisara_opens'];
                        $share = $all > 0 ? round($row['kisara_opens'] / $all * 100) : null;
                    @endphp
                    <tr wire:key="usage-{{ $row['week'] }}">
                        <td class="px-4 py-2.5 text-sm text-neutral-700 dark:text-neutral-200">{{ \Carbon\Carbon::parse($row['week'])->locale('id')->translatedFormat('j M Y') }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-neutral-700 dark:text-neutral-200">{{ number_format($row['roundcube_opens'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-neutral-500 dark:text-neutral-400">{{ number_format($row['roundcube_users'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-neutral-700 dark:text-neutral-200">{{ number_format($row['kisara_opens'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-neutral-500 dark:text-neutral-400">{{ number_format($row['kisara_users'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-neutral-700 dark:text-neutral-200">{{ $share === null ? '—' : $share.' %' }}</td>
                    </tr>
                @endforeach
            </x-slot:table>
        </x-nawasara-ui::table>
        <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
            Satu pembukaan = sekali per sesi per mailbox. Jejaknya disimpan 1 tahun.
        </p>
    @endif
</div>
