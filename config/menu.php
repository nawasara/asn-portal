<?php

$prefix = 'nawasara-asn-portal';

/*
| Sidebar for the ASN account portal's content.
|
| Own workspace id: no other package contributes to it, and sharing an id
| would take the label and icon from whichever package loads first
| alphabetically (AGENTS.md §5).
|
| `group` must be one of WorkspaceManager::GROUP_ORDER, or the workspace
| lands silently under "Lainnya".
*/

return [
    [
        'workspace' => 'asn-portal',
        'label' => 'Portal ASN',
        'icon' => 'lucide-id-card',
        'group' => 'Layanan',
        'url' => '',
        // No workspace-level permission: each submenu has its own, so staff
        // who may only manage Akses Layanan Kisara (or only read usage) still
        // see this workspace (WorkspaceManager::accessible).
        'permission' => null,
        'submenu' => [
            [
                'section' => 'Konten Portal',
                'icon' => 'lucide-layout-template',
                'permission' => 'asn-portal.content.view',
            ],
            [
                'label' => 'Katalog Aplikasi',
                'icon' => 'lucide-layout-grid',
                'url' => url($prefix.'/apps'),
                'permission' => 'asn-portal.content.view',
                'navigate' => true,
            ],
            [
                'label' => 'Bantuan',
                'icon' => 'lucide-circle-help',
                'url' => url($prefix.'/help'),
                'permission' => 'asn-portal.content.view',
                'navigate' => true,
            ],
            [
                'label' => 'Dokumen Hukum',
                'icon' => 'lucide-scale',
                'url' => url($prefix.'/legal'),
                'permission' => 'asn-portal.content.view',
                'navigate' => true,
            ],
            [
                'section' => 'Layanan Kisara',
                'icon' => 'lucide-key-round',
                'permission' => 'asn-portal.access.view',
            ],
            [
                'label' => 'Akses Layanan Kisara',
                'icon' => 'lucide-user-check',
                'url' => url($prefix.'/access'),
                'permission' => 'asn-portal.access.view',
                'navigate' => true,
            ],
            [
                'section' => 'Pemantauan',
                'icon' => 'lucide-chart-column',
                'permission' => 'asn-portal.usage.view',
            ],
            [
                'label' => 'Pemakaian Email',
                'icon' => 'lucide-mail',
                'url' => url($prefix.'/usage'),
                'permission' => 'asn-portal.usage.view',
                'navigate' => true,
            ],
        ],
    ],
];
