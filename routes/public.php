<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\PublicContentController;
use Nawasara\AsnPortal\Models\LegalDocument;

/*
|--------------------------------------------------------------------------
| Public endpoints — /api/v1/asn-portal/public/*
|--------------------------------------------------------------------------
| No login. Rate limited per IP. Read-only content for the guest pages.
*/

Route::get('/apps', [PublicContentController::class, 'apps'])->name('apps');
Route::get('/help', [PublicContentController::class, 'help'])->name('help');
Route::get('/legal/{type}', [PublicContentController::class, 'legal'])
    ->whereIn('type', LegalDocument::TYPES)
    ->name('legal');
