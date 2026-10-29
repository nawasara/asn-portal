<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\AccountController;
use Nawasara\AsnPortal\Support\AccountActions;

/*
|--------------------------------------------------------------------------
| Account endpoints — /api/v1/asn-portal/account/*
|--------------------------------------------------------------------------
| Behind api.staff.identity (every civil servant, no users row needed) and
| api.client (only the portal's own Keycloak client). Identity is always the
| `sub` on the token.
*/

Route::get('/', [AccountController::class, 'show'])->name('show');

// Static before {action}.
Route::post('/reauth', [AccountController::class, 'reauth'])->name('reauth');

Route::post('/actions/{action}', [AccountController::class, 'startAction'])
    ->whereIn('action', array_keys(AccountActions::ACTIONS))
    ->name('actions.start');
