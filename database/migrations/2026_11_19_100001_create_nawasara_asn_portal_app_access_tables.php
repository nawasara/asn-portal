<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nawasara\AsnPortal\Database\Seeders\KisaraAccessImportSeeder;

/*
| Akses Layanan Kisara: one list of who may use which Kisara app during the
| pilot (Kisara Workspace, Kisara Mail, and later tools), replacing the
| Workspace pilot table and Kisara Mail's PILOT_SUBS.
|
| bigint, not UUID: internal, never referenced from outside (AGENTS §10a).
| The API looks rows up by NIP + app, never by id.
|
| One row per (nip, app), unique over ALL rows rather than only the active
| ones: MySQL has no partial unique index, and the generated-column trick is
| not worth it here. "Nonaktifkan" sets removed_at on the same row and
| "Aktifkan" clears it, so a person never needs a second row for the same
| app; the history of who did what is in the activity log.
|
| opd_id has no foreign key on purpose: nawasara/asn-portal does not depend
| on nawasara/registry. It is only filled for apps whose registry entry says
| `opd_override` (Kisara Workspace: Folder OPD when SIMAS is wrong).
|
| The settings table holds the per-app kill switch and "open to every ASN".
|
| The import at the end copies the current Workspace pilot list in the same
| migrate run: nawasara/workspace switches to this table as soon as it
| exists, so a separate manual step would lock every pilot member out of
| Workspace between deploy and that step.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_asn_portal_app_access', function (Blueprint $table) {
            $table->id();
            $table->string('nip', 18);
            $table->string('app', 32);
            $table->string('name');
            $table->string('keycloak_id', 64)->nullable()->index();
            $table->unsignedTinyInteger('stage')->default(1);
            $table->unsignedBigInteger('opd_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['nip', 'app'], 'nawasara_asn_portal_app_access_nip_app_unique');
            // Group sync and counts: "active rows of one app".
            $table->index(['app', 'removed_at'], 'nawasara_asn_portal_app_access_app_removed_index');
        });

        Schema::create('nawasara_asn_portal_app_access_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app', 32)->unique();
            $table->boolean('kill_switch')->default(false);
            $table->boolean('open_to_all')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        (new KisaraAccessImportSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_asn_portal_app_access_settings');
        Schema::dropIfExists('nawasara_asn_portal_app_access');
    }
};
