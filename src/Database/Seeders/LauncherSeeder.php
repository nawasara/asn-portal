<?php

namespace Nawasara\AsnPortal\Database\Seeders;

use Illuminate\Database\Seeder;
use Nawasara\AsnPortal\Models\PortalApp;

/**
 * The 9-dot launcher entries of FR-03 AC-2, in menu order:
 *   Kisara Workspace: Docs, Sheets, Slides, Drive, Mail, Akun
 *   Ekosistem Kisara: Portal ASN, Nawasara, Gasta
 * ("Semua aplikasi" is a link from config, not a catalog row.)
 *
 * Run once by hand; it is not called by a migration, so deploying the
 * package changes nothing until someone decides to fill the launcher:
 *   php artisan db:seed --class="Nawasara\AsnPortal\Database\Seeders\LauncherSeeder"
 *
 * Portal ASN stays as it is:
 * - an entry that already exists in the catalog (same slug, else same URL)
 *   only gets its launcher_* columns, and only while launcher_section is
 *   still empty, so an admin's later edit is never undone;
 * - a missing entry is created as a launcher-only row (show_in_portal =
 *   false), so it never appears as a card on Portal ASN.
 */
class LauncherSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->entries() as $entry) {
            $launcher = array_intersect_key($entry, array_flip([
                'launcher_section', 'launcher_sort', 'launcher_audience', 'launcher_label', 'launcher_icon',
            ]));

            $existing = PortalApp::where('slug', $entry['slug'])->first()
                ?? PortalApp::query()->get()->first(
                    fn (PortalApp $app) => rtrim($app->url, '/') === rtrim($entry['url'], '/')
                );

            if ($existing) {
                if ($existing->launcher_section === null) {
                    $existing->update($launcher);
                }

                continue;
            }

            PortalApp::create($entry + ['is_active' => true, 'show_in_portal' => false, 'sort_order' => 0]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        $ws = PortalApp::SECTION_WORKSPACE;
        $eco = PortalApp::SECTION_ECOSYSTEM;
        // Audience = app key of Akses Layanan Kisara: shown only to whoever
        // may use that app, so the menu never offers a door that refuses.
        $workspaceAudience = 'workspace';
        $mailAudience = 'mail';
        $all = PortalApp::AUDIENCE_ALL;
        $docs = rtrim((string) config('nawasara-asn-portal.launcher.workspace_url', 'https://docs.ponorogo.go.id'), '/');

        return [
            ['slug' => 'kisara-docs', 'name' => 'Kisara Docs', 'url' => $docs.'/docs', 'launcher_section' => $ws, 'launcher_sort' => 10, 'launcher_audience' => $workspaceAudience, 'launcher_label' => 'Docs', 'launcher_icon' => 'docs'],
            ['slug' => 'kisara-sheets', 'name' => 'Kisara Sheets', 'url' => $docs.'/sheets', 'launcher_section' => $ws, 'launcher_sort' => 20, 'launcher_audience' => $workspaceAudience, 'launcher_label' => 'Sheets', 'launcher_icon' => 'sheets'],
            ['slug' => 'kisara-slides', 'name' => 'Kisara Slides', 'url' => $docs.'/slides', 'launcher_section' => $ws, 'launcher_sort' => 30, 'launcher_audience' => $workspaceAudience, 'launcher_label' => 'Slides', 'launcher_icon' => 'slides'],
            ['slug' => 'kisara-drive', 'name' => 'Kisara Drive', 'url' => $docs.'/drive', 'launcher_section' => $ws, 'launcher_sort' => 40, 'launcher_audience' => $workspaceAudience, 'launcher_label' => 'Drive', 'launcher_icon' => 'drive'],
            ['slug' => 'kisara-mail', 'name' => 'Kisara Mail', 'url' => 'https://patra.ponorogo.go.id', 'launcher_section' => $ws, 'launcher_sort' => 50, 'launcher_audience' => $mailAudience, 'launcher_label' => 'Mail', 'launcher_icon' => 'kisara-mail'],
            ['slug' => 'akun-kisara', 'name' => 'Akun Kisara', 'url' => 'https://asn-account.ponorogo.go.id', 'launcher_section' => $ws, 'launcher_sort' => 60, 'launcher_audience' => $all, 'launcher_label' => 'Akun', 'launcher_icon' => 'akun-kisara'],
            ['slug' => 'portal-asn', 'name' => 'Portal ASN', 'url' => 'https://asn.ponorogo.go.id', 'launcher_section' => $eco, 'launcher_sort' => 10, 'launcher_audience' => $all, 'launcher_label' => 'Portal ASN', 'launcher_icon' => 'portal-asn'],
            ['slug' => 'nawasara', 'name' => 'Nawasara', 'url' => 'https://nawasara.ponorogo.go.id', 'launcher_section' => $eco, 'launcher_sort' => 20, 'launcher_audience' => $all, 'launcher_label' => 'Nawasara', 'launcher_icon' => 'nawasara'],
            ['slug' => 'gasta', 'name' => 'Gasta', 'url' => 'https://gasta.ponorogo.go.id', 'launcher_section' => $eco, 'launcher_sort' => 30, 'launcher_audience' => $all, 'launcher_label' => 'Gasta', 'launcher_icon' => 'gasta'],
        ];
    }
}
