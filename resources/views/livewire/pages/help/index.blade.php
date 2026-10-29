<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Portal ASN', 'url' => '#'], ['label' => 'Bantuan']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-asn-portal.help.section.table />
    </x-nawasara-ui::page.container>
</div>
