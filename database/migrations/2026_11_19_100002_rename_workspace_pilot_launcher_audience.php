<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Launcher audience is now "all" or an app key from Akses Layanan Kisara
| (`workspace`, `mail`, ...). The old value `workspace-pilot` meant exactly
| "who may use Kisara Workspace", so it becomes `workspace`. LauncherController
| still reads `workspace-pilot` as `workspace`, so a row written by an older
| release between deploy and migrate is not hidden.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('nawasara_asn_portal_apps')->where('launcher_audience', 'workspace-pilot')->update(['launcher_audience' => 'workspace']);
    }

    public function down(): void
    {
        DB::table('nawasara_asn_portal_apps')->where('launcher_audience', 'workspace')->update(['launcher_audience' => 'workspace-pilot']);
    }
};
