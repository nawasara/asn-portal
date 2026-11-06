<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Collection;

/**
 * The servers (Proxmox VMs and LXC containers) a civil servant looks after,
 * for the "Server / VM" section of the ASN account portal.
 *
 * PICs come from nawasara/registry: nawasara/proxmox mirrors each VM there as
 * a "server" asset keyed by vmid (VmRegistrySync). Live figures come from
 * the proxmox snapshot table, so this costs two queries and never calls the
 * Proxmox API on a page load.
 *
 * Every field below is listed on purpose (allow-list, AGENTS.md §10a): no
 * internal ids, no node credentials, no console links. Opening a console
 * stays a panel action, gated by its own permission and audit trail.
 */
class ServerDirectory
{
    public const ASSET_TYPES = ['server'];

    public function forUser(string $sub): Collection
    {
        if (! class_exists(\Nawasara\Registry\Services\AssetAssignmentService::class)
            || ! class_exists(\Nawasara\Proxmox\Models\ProxmoxVm::class)) {
            return collect();
        }

        $assets = app(\Nawasara\Registry\Services\AssetAssignmentService::class)
            ->assetsForKeycloakSub($sub, self::ASSET_TYPES)
            ->where('package_ref', 'proxmox');

        if ($assets->isEmpty()) {
            return collect();
        }

        $vmids = $assets->pluck('external_id')->map(fn ($id) => (int) $id)->all();

        $vms = \Nawasara\Proxmox\Models\ProxmoxVm::query()
            ->whereIn('vmid', $vmids)
            ->get()
            ->keyBy('vmid');

        $console = class_exists(\Nawasara\Proxmox\Services\KisaraConsole::class)
            ? app(\Nawasara\Proxmox\Services\KisaraConsole::class)
            : null;
        $consoleOn = $console?->enabled() ?? false;

        $ips = class_exists(\Nawasara\Proxmox\Models\ProxmoxIpAddress::class)
            ? \Nawasara\Proxmox\Models\ProxmoxIpAddress::query()
                ->whereIn('vmid', $vmids)
                ->orderBy('ip_numeric')
                ->get(['vmid', 'ip'])
                ->groupBy('vmid')
                ->map(fn ($rows) => $rows->pluck('ip')->unique()->values()->all())
            : collect();

        return $assets->map(function ($asset) use ($vms, $ips, $console, $consoleOn) {
            $vmid = (int) $asset->external_id;
            $vm = $vms->get($vmid);

            return [
                'name' => $vm?->name ?? $asset->identifier,
                'vmid' => $vmid,
                'kind' => $vm?->vm_type === 'lxc' ? 'container' : 'vm',
                'role' => $asset->my_role,
                'opd' => $asset->opd?->name,
                'pics' => $asset->assignments
                    ->sortBy(fn ($a) => $a->role === 'primary' ? 0 : 1)
                    ->map(fn ($a) => $a->user?->name)
                    ->filter()->unique()->values()->all(),
                // null when the VM row is missing between a deletion and the
                // registry marking the asset inactive.
                'status' => $vm?->status,
                'cpu_count' => $vm?->cpu_count,
                'cpu_percent' => $vm && $vm->isRunning() && $vm->cpu_usage !== null
                    ? round($vm->cpu_usage * 100, 1) : null,
                'memory_bytes' => $vm?->mem_total,
                'memory_percent' => $vm && $vm->isRunning() ? $vm->memUsagePercent() : null,
                'disk_bytes' => $vm?->disk_total,
                // QEMU reports 0 without the guest agent (see proxmox README):
                // null, not 0%, so the portal does not paint it as empty.
                'disk_percent' => $vm?->vm_type === 'lxc' ? $vm->diskUsagePercent() : null,
                'uptime_seconds' => $vm && $vm->isRunning() ? $vm->uptime : null,
                'ip_addresses' => $ips->get($vmid, []),
                'synced_at' => $vm?->last_synced_at?->toIso8601String(),
                // Tombol "Buka console" di Akun Kisara: hanya container yang
                // menyala, dan hanya bila console Kisara sudah disiapkan.
                'console' => $consoleOn && $console->supports($vm),
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
