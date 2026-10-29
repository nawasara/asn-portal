<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Catalog of applications that sign in through the employee realm.
|
| One source for two screens: the guest directory on the portal's public
| home, and "Aplikasi terhubung", which matches a Keycloak session's client
| to a readable name. Before this table the list was a hardcoded PHP array in
| the old portal (AppsService::getApps()), so adding an app meant a deploy.
|
| Internal table, so a bigint key (AGENTS.md §10a): rows are addressed by
| `slug`, never by id, from outside.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_asn_portal_apps', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('url', 500);

            // Keycloak client id in realm asn-productions. Nullable because
            // some catalog entries are links to apps that do not use SSO yet.
            $table->string('keycloak_client_id', 120)->nullable()->unique();

            $table->string('category', 60)->nullable();
            $table->string('abbreviation', 4)->nullable();
            $table->string('color', 9)->nullable();
            $table->string('icon_url', 500)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_asn_portal_apps');
    }
};
