<?php

namespace Nawasara\AsnPortal\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the Kisara Workspace pilot list into Akses Layanan Kisara.
 *
 * Called by the migration that creates the access tables, so the copy lands
 * in the same `migrate` run. Safe to run again by hand:
 *   php artisan db:seed --class="Nawasara\AsnPortal\Database\Seeders\KisaraAccessImportSeeder"
 *
 * - every ACTIVE row of nawasara_workspace_pilot_members becomes a row for
 *   app `workspace` AND app `mail` (the owner's decision: everyone in the
 *   Workspace pilot also tries Kisara Mail), keeping stage, note, added_by,
 *   the Keycloak id, and the date they were added;
 * - the OPD override is copied for `workspace` only, the one app that reads it;
 * - a (nip, app) that already has a row is left exactly as it is, active or
 *   not, so running this after an admin changed something undoes nothing;
 * - Workspace's kill switch and pilot_open become the `workspace` app
 *   settings, only when that app has no settings row yet;
 * - revoked pilot rows are not copied: they grant nothing, and copying them
 *   would put people back in the list the admin took them off.
 *
 * Reads the Workspace tables with the query builder, not its models:
 * nawasara/asn-portal does not depend on nawasara/workspace, and an install
 * without it simply has nothing to copy.
 */
class KisaraAccessImportSeeder extends Seeder
{
    public const APPS = ['workspace', 'mail'];

    private const ACCESS = 'nawasara_asn_portal_app_access';

    private const SETTINGS = 'nawasara_asn_portal_app_access_settings';

    /**
     * @return array{members: int, created: array<string, int>, kept: int, invalid: int, settings_copied: bool}
     */
    public function run(): array
    {
        $out = ['members' => 0, 'created' => array_fill_keys(self::APPS, 0), 'kept' => 0, 'invalid' => 0, 'settings_copied' => false];

        if (! Schema::hasTable(self::ACCESS) || ! Schema::hasTable('nawasara_workspace_pilot_members')) {
            return $out;
        }

        $members = DB::table('nawasara_workspace_pilot_members')->whereNull('removed_at')->orderBy('id')->get();
        $out['members'] = $members->count();

        foreach ($members as $m) {
            $nip = trim((string) $m->nip);
            if (preg_match('/^\d{18}$/', $nip) !== 1) {
                $out['invalid']++;

                continue;
            }

            foreach (self::APPS as $app) {
                if (DB::table(self::ACCESS)->where('nip', $nip)->where('app', $app)->exists()) {
                    $out['kept']++;

                    continue;
                }

                DB::table(self::ACCESS)->insert([
                    'nip' => $nip,
                    'app' => $app,
                    'name' => (string) $m->name,
                    'keycloak_id' => $m->keycloak_id,
                    'stage' => (int) $m->stage,
                    'opd_id' => $app === 'workspace' ? $m->opd_id : null,
                    'note' => $m->note,
                    'added_by' => $m->added_by,
                    'created_at' => $m->created_at ?? now(),
                    'updated_at' => now(),
                ]);
                $out['created'][$app]++;
            }
        }

        $out['settings_copied'] = $this->copySettings();

        Log::info('asn-portal: Workspace pilot list copied into Akses Layanan Kisara', $out);

        return $out;
    }

    private function copySettings(): bool
    {
        if (! Schema::hasTable(self::SETTINGS) || ! Schema::hasTable('nawasara_workspace_settings')) {
            return false;
        }

        if (DB::table(self::SETTINGS)->where('app', 'workspace')->exists()) {
            return false;
        }

        $flag = fn (string $key) => DB::table('nawasara_workspace_settings')->where('key', $key)->value('value') === '1';

        DB::table(self::SETTINGS)->insert([
            'app' => 'workspace',
            'kill_switch' => $flag('kill_switch'),
            'open_to_all' => $flag('pilot_open'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }
}
