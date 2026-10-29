<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Portal ASN', 'url' => '#'], ['label' => 'Katalog Aplikasi']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-asn-portal.app.section.table />
    </x-nawasara-ui::page.container>
</div>
