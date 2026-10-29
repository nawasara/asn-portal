<?php

namespace Nawasara\AsnPortal\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Panel pages that manage the portal's public content: app
            // catalog, help items, legal documents.
            'asn-portal.content.view',
            'asn-portal.content.manage',

            // Publishing a legal document is split from editing drafts: a
            // published version binds every civil servant and cannot be
            // changed afterwards.
            'asn-portal.legal.publish',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'developer')->first();

        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}
