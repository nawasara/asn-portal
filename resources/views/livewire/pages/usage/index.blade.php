<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Portal ASN', 'url' => '#'], ['label' => 'Pemakaian Email']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <livewire:nawasara-asn-portal.usage.section.table />
    </x-nawasara-ui::page.container>
</div>
