<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Collection;
use Nawasara\AsnPortal\Support\SiteHost;

/**
 * The sites a civil servant looks after (registry assets where they are a
 * PIC), each with what other packages already know about it: uptime (Kuma),
 * hosting disk (WHM), PHP version (secscan) and open security findings.
 *
 * There is no link table between those packages; rows are matched on the
 * normalised host (SiteHost). Each source is optional (class_exists) and a
 * site with nothing known still appears, with nulls.
 *
 * Every lookup is one query for all of the user's sites, not one per site:
 * a PIC of a whole agency can hold dozens.
 */
class SiteDirectory
{
    public const ASSET_TYPES = ['domain', 'subdomain', 'hosting', 'hosting_account', 'application'];

    public function forUser(string $sub): Collection
    {
        $sites = $this->assets($sub);

        if ($sites->isEmpty()) {
            return collect();
        }

        $hosts = $sites->keys()->all();
        $uptime = $this->uptime($hosts);
        $hosting = $this->hosting($hosts);
        $php = $this->php($hosts);
        $findings = $this->findings($hosts);

        return $sites->map(fn (array $site, string $host) => $site + [
            'uptime' => $uptime[$host] ?? null,
            'hosting' => $hosting[$host] ?? null,
            'php' => $php[$host] ?? null,
            'findings' => $findings[$host] ?? ['open' => 0, 'critical' => 0],
        ])->values();
    }

    /** @return Collection<string, array> keyed by host; primary wins over backup */
    private function assets(string $sub): Collection
    {
        if (! class_exists(\Nawasara\Registry\Services\AssetAssignmentService::class)) {
            return collect();
        }

        $labels = (array) config('nawasara-registry.asset_types', []);
        $sites = collect();

        $assets = app(\Nawasara\Registry\Services\AssetAssignmentService::class)
            ->assetsForKeycloakSub($sub, self::ASSET_TYPES);

        foreach ($assets as $asset) {
            $host = SiteHost::normalize($asset->identifier);

            if ($host === null || ($sites->has($host) && $sites[$host]['role'] === 'primary')) {
                continue;
            }

            $sites->put($host, [
                'host' => $host,
                'url' => 'https://'.$host,
                'type' => $labels[$asset->type] ?? $asset->type,
                'role' => $asset->my_role,
                'opd' => $asset->opd?->name,
                'pics' => $asset->assignments
                    ->sortBy(fn ($a) => $a->role === 'primary' ? 0 : 1)
                    ->map(fn ($a) => $a->user?->name)
                    ->filter()->unique()->values()->all(),
            ]);
        }

        return $sites->sortKeys();
    }

    private function uptime(array $hosts): array
    {
        if (! class_exists(\Nawasara\Uptime\Models\UptimeMonitor::class)) {
            return [];
        }

        $wanted = array_flip($hosts);
        $out = [];

        // The monitor table is small (one row per Kuma monitor) and its host
        // lives inside `url` or `hostname`, so it is matched in PHP.
        $monitors = \Nawasara\Uptime\Models\UptimeMonitor::query()
            ->where('active', true)
            ->get(['url', 'hostname', 'current_status', 'current_ping', 'uptime_7d', 'uptime_30d', 'last_check_at']);

        foreach ($monitors as $m) {
            $host = SiteHost::normalize($m->url) ?? SiteHost::normalize($m->hostname);

            if ($host === null || ! isset($wanted[$host]) || isset($out[$host])) {
                continue;
            }

            $out[$host] = [
                'status' => match ($m->current_status) {
                    \Nawasara\Uptime\Models\UptimeMonitor::STATUS_UP => 'up',
                    \Nawasara\Uptime\Models\UptimeMonitor::STATUS_DOWN => 'down',
                    \Nawasara\Uptime\Models\UptimeMonitor::STATUS_MAINTENANCE => 'maintenance',
                    default => 'pending',
                },
                'ping_ms' => $m->current_ping,
                'uptime_7d' => $m->uptime_7d,
                'uptime_30d' => $m->uptime_30d,
                'checked_at' => $m->last_check_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    private function hosting(array $hosts): array
    {
        if (! class_exists(\Nawasara\Whm\Models\WhmAccount::class)) {
            return [];
        }

        return \Nawasara\Whm\Models\WhmAccount::query()
            ->whereIn('domain', $this->withWww($hosts))
            ->get(['domain', 'disk_used_mb', 'disk_limit_mb', 'suspended'])
            ->mapWithKeys(fn ($a) => [(string) SiteHost::normalize($a->domain) => [
                'disk_used_mb' => $a->disk_used_mb !== null ? round((float) $a->disk_used_mb, 1) : null,
                'disk_limit_mb' => $a->disk_limit_mb ?: null,
                'suspended' => (bool) $a->suspended,
            ]])
            ->all();
    }

    private function php(array $hosts): array
    {
        if (! class_exists(\Nawasara\Secscan\Models\PhpRuntime::class)) {
            return [];
        }

        $out = [];

        // Most recently seen wins when several sources saw the same host.
        $rows = \Nawasara\Secscan\Models\PhpRuntime::query()
            ->whereIn('target', $this->withWww($hosts))
            ->orderByDesc('last_seen_at')
            ->get(['target', 'php_version', 'status', 'last_seen_at']);

        foreach ($rows as $r) {
            $host = SiteHost::normalize($r->target);

            if ($host !== null && ! isset($out[$host])) {
                $out[$host] = [
                    'version' => $r->php_version,
                    'status' => $r->status,
                    'seen_at' => $r->last_seen_at?->toIso8601String(),
                ];
            }
        }

        return $out;
    }

    private function findings(array $hosts): array
    {
        if (! class_exists(\Nawasara\Secscan\Models\SecscanFinding::class)) {
            return [];
        }

        $wanted = array_flip($hosts);
        $out = [];

        // Findings carry no domain column, only site_url; the open set is
        // small, so it is matched in PHP like the monitors.
        $rows = \Nawasara\Secscan\Models\SecscanFinding::query()
            ->active()
            ->whereNotNull('site_url')
            ->get(['site_url', 'severity']);

        foreach ($rows as $f) {
            $host = SiteHost::normalize($f->site_url);

            if ($host === null || ! isset($wanted[$host])) {
                continue;
            }

            $out[$host] ??= ['open' => 0, 'critical' => 0];
            $out[$host]['open']++;

            if ($f->severity === \Nawasara\Secscan\Models\SecscanFinding::SEVERITY_CRITICAL) {
                $out[$host]['critical']++;
            }
        }

        return $out;
    }

    private function withWww(array $hosts): array
    {
        return array_merge($hosts, array_map(fn ($h) => 'www.'.$h, $hosts));
    }
}
