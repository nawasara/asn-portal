<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Livewire\App\Index as AppIndex;
use Nawasara\AsnPortal\Livewire\Help\Index as HelpIndex;
use Nawasara\AsnPortal\Livewire\Legal\Index as LegalIndex;
use Nawasara\AsnPortal\Livewire\Usage\Index as UsageIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-asn-portal')->group(function () {
    Route::get('apps', AppIndex::class)
        ->middleware(PermissionMiddleware::using('asn-portal.content.view'))
        ->name('nawasara-asn-portal.apps.index');

    Route::get('help', HelpIndex::class)
        ->middleware(PermissionMiddleware::using('asn-portal.content.view'))
        ->name('nawasara-asn-portal.help.index');

    Route::get('legal', LegalIndex::class)
        ->middleware(PermissionMiddleware::using('asn-portal.content.view'))
        ->name('nawasara-asn-portal.legal.index');

    Route::get('usage', UsageIndex::class)
        ->middleware(PermissionMiddleware::using('asn-portal.usage.view'))
        ->name('nawasara-asn-portal.usage.index');
});
