<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Portal ASN', 'url' => '#'], ['label' => 'Akses Layanan Kisara']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-asn-portal.access.section.controls />
        <livewire:nawasara-asn-portal.access.section.table />
        <livewire:nawasara-asn-portal.access.section.form />
    </x-nawasara-ui::page.container>
</div>
