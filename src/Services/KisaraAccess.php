<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Nawasara\AsnPortal\Models\AppAccess;
use Nawasara\AsnPortal\Models\AppAccessSetting;
use Nawasara\Keycloak\Models\KeycloakUser;

/**
 * Who may use which Kisara app right now (Akses Layanan Kisara).
 *
 * One rule set for every door of every app: the access API Kisara Mail asks,
 * Workspace's own access API and Keycloak group (nawasara/workspace asks this
 * class when it is installed), and the 9-dot launcher. If two doors decided
 * differently, someone could be refused by one and let in by the other.
 *
 * Same order and reason words as Workspace's AccessDecider, so callers that
 * already understand those answers understand these:
 *   0. unknown_app      not in config `nawasara-asn-portal.access.apps`
 *   1. invalid_nip      not 18 digits
 *   2. kill_switch      the app is off for everybody
 *   3. account_disabled the Kisara (Keycloak) account is disabled
 *   4. open to all      stage 3: every ASN
 *   5. not_participant  no row for this app
 *      revoked          a row, but deactivated
 */
class KisaraAccess
{
    public const UNKNOWN_APP = 'unknown_app';

    public const INVALID_NIP = 'invalid_nip';

    public const KILL_SWITCH = 'kill_switch';

    public const ACCOUNT_DISABLED = 'account_disabled';

    public const NOT_PARTICIPANT = 'not_participant';

    public const REVOKED = 'revoked';

    /** Stage reported while an app is open to every ASN. */
    public const STAGE_OPEN = 3;

    public const FLAG_KILL_SWITCH = 'kill_switch';

    public const FLAG_OPEN = 'open_to_all';

    /** @return array<string, array{label: string, short: string, description: ?string, opd_override: bool}> */
    public static function apps(): array
    {
        $apps = [];
        foreach ((array) config('nawasara-asn-portal.access.apps', []) as $key => $app) {
            // `all` is the launcher audience for everyone; an app by that
            // name would make "everyone" mean "only this app's list".
            if (! is_string($key) || $key === 'all' || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) !== 1) {
                continue;
            }
            $label = (string) ($app['label'] ?? $key);
            $apps[$key] = [
                'label' => $label,
                'short' => (string) ($app['short'] ?? $label),
                'description' => isset($app['description']) ? (string) $app['description'] : null,
                'opd_override' => (bool) ($app['opd_override'] ?? false),
            ];
        }

        return $apps;
    }

    public static function knows(string $app): bool
    {
        return array_key_exists($app, static::apps());
    }

    /** @return array<int, string> */
    public static function stages(): array
    {
        return (array) config('nawasara-asn-portal.access.stages', [0 => 'Tahap 0', 1 => 'Tahap 1', 2 => 'Tahap 2']);
    }

    /**
     * @return array{nip: string, app: string, allowed: bool, reason: ?string, stage: ?int, opd_id: ?int}
     */
    public function allows(string $app, string $nip): array
    {
        $deny = fn (string $reason) => ['nip' => $nip, 'app' => $app, 'allowed' => false, 'reason' => $reason, 'stage' => null, 'opd_id' => null];

        if (! static::knows($app)) {
            return $deny(self::UNKNOWN_APP);
        }

        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return $deny(self::INVALID_NIP);
        }

        $flags = $this->flags($app);

        if ($flags[self::FLAG_KILL_SWITCH]) {
            return $deny(self::KILL_SWITCH);
        }

        // From the Keycloak snapshot. An account missing from the snapshot is
        // not treated as disabled: the snapshot syncs hourly, and a brand-new
        // ASN would otherwise be refused for up to an hour for no reason.
        $enabled = KeycloakUser::where('username', $nip)->value('enabled');
        if ($enabled === false || $enabled === 0) {
            return $deny(self::ACCOUNT_DISABLED);
        }

        $row = AppAccess::where('nip', $nip)->where('app', $app)->first();

        if ($flags[self::FLAG_OPEN]) {
            return ['nip' => $nip, 'app' => $app, 'allowed' => true, 'reason' => null, 'stage' => self::STAGE_OPEN, 'opd_id' => $row?->opd_id];
        }

        if (! $row) {
            return $deny(self::NOT_PARTICIPANT);
        }

        if (! $row->isActive()) {
            return $deny(self::REVOKED);
        }

        return ['nip' => $nip, 'app' => $app, 'allowed' => true, 'reason' => null, 'stage' => $row->stage, 'opd_id' => $row->opd_id];
    }

    /**
     * In the app's audience regardless of the kill switch: open to all, or an
     * active row. For things that outlive a temporary switch-off (a Drive
     * quota card: the files are still there).
     */
    public function isMember(string $app, string $nip): bool
    {
        return static::knows($app)
            && ($this->flags($app)[self::FLAG_OPEN]
                || AppAccess::where('nip', $nip)->where('app', $app)->whereNull('removed_at')->exists());
    }

    /**
     * Active rows of one app (nip, keycloak_id, stage, opd_id), for an app
     * that mirrors the list somewhere else (Workspace's Keycloak group).
     *
     * @return Collection<int, AppAccess>
     */
    public function activeMembers(string $app): Collection
    {
        return AppAccess::query()->forApp($app)->active()->orderBy('id')
            ->get(['id', 'nip', 'name', 'keycloak_id', 'stage', 'opd_id']);
    }

    /**
     * NIP => OPD id for every row of the app that carries an override,
     * active or not: the OPD is about where someone works, not whether they
     * are in the pilot (Workspace provisions accounts for every ASN).
     *
     * @return array<string, int>
     */
    public function opdOverrides(string $app): array
    {
        return AppAccess::query()->forApp($app)->whereNotNull('opd_id')
            ->pluck('opd_id', 'nip')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array{kill_switch: bool, open_to_all: bool} */
    public function flags(string $app): array
    {
        return Cache::remember(static::cacheKey($app), max(1, (int) config('nawasara-asn-portal.access.settings_cache_seconds', 30)), function () use ($app) {
            $row = AppAccessSetting::where('app', $app)->first();

            return [
                self::FLAG_KILL_SWITCH => (bool) ($row?->kill_switch ?? false),
                self::FLAG_OPEN => (bool) ($row?->open_to_all ?? false),
            ];
        });
    }

    public function setFlag(string $app, string $flag, bool $on, ?int $userId = null): void
    {
        if (! static::knows($app) || ! in_array($flag, [self::FLAG_KILL_SWITCH, self::FLAG_OPEN], true)) {
            throw new \InvalidArgumentException("Unknown Kisara app or flag: {$app}/{$flag}");
        }

        // Forgotten on both sides: the save fires KisaraAccessChanged, and a
        // listener running synchronously must not read the old value.
        Cache::forget(static::cacheKey($app));
        AppAccessSetting::updateOrCreate(['app' => $app], [$flag => $on, 'updated_by' => $userId]);
        Cache::forget(static::cacheKey($app));
    }

    public static function cacheKey(string $app): string
    {
        return "nawasara-asn-portal:access-flags:{$app}";
    }
}
