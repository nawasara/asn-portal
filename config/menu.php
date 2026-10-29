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
        'permission' => 'asn-portal.content.view',
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
        ],
    ],
];
