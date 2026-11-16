<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The 9-dot launcher shared by Akun Kisara, Kisara Mail and Kisara Workspace
| reads the same catalog as Portal ASN (ADR 0006), but with its own columns:
| trimming or reordering the portal list to fit the launcher would change
| what every civil servant sees on Portal ASN.
|
| - launcher_section  null = not in the launcher; `workspace` | `ecosystem`
| - launcher_sort     order inside the section, apart from sort_order
| - launcher_audience `all` | `workspace-pilot` (only pilot participants)
| - launcher_label    short label under the icon ("Docs"), name when null
| - launcher_icon     icon KEY each product maps to its own asset
| - show_in_portal    false for launcher-only rows (Docs, Drive, ...), so
|                     adding them never adds a card to Portal ASN. Default
|                     true keeps every existing row exactly where it was.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_asn_portal_apps', function (Blueprint $table) {
            $table->boolean('show_in_portal')->default(true)->after('is_active');
            $table->string('launcher_section', 16)->nullable()->after('show_in_portal');
            $table->unsignedInteger('launcher_sort')->default(0)->after('launcher_section');
            $table->string('launcher_audience', 32)->default('all')->after('launcher_sort');
            $table->string('launcher_label', 40)->nullable()->after('launcher_audience');
            $table->string('launcher_icon', 64)->nullable()->after('launcher_label');

            $table->index(['launcher_section', 'launcher_sort'], 'nawasara_asn_portal_apps_launcher_index');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_asn_portal_apps', function (Blueprint $table) {
            $table->dropIndex('nawasara_asn_portal_apps_launcher_index');
            $table->dropColumn([
                'show_in_portal', 'launcher_section', 'launcher_sort',
                'launcher_audience', 'launcher_label', 'launcher_icon',
            ]);
        });
    }
};
