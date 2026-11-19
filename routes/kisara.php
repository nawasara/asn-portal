<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\KisaraAccessController;
use Nawasara\AsnPortal\Http\Api\LauncherController;

/*
|--------------------------------------------------------------------------
| Shared Kisara endpoints: /api/v1/kisara/*
|--------------------------------------------------------------------------
| Called by the servers of Akun Kisara, Kisara Mail and Kisara Workspace
| with their own nws_ tokens, never by a browser. One scope per route, so a
| launcher token cannot ask about access and the other way round.
*/

Route::get('/launcher', [LauncherController::class, 'index'])
    ->middleware(['scope:asn-portal.launcher.read', 'throttle:nawasara-asn-portal-launcher'])
    ->name('launcher');

// Any segment reaches the controller, so a login name that is not a NIP
// gets `invalid_nip` instead of a bare 404.
Route::get('/access/{nip}', [KisaraAccessController::class, 'show'])
    ->where('nip', '[^/]{1,64}')
    ->middleware(['scope:asn-portal.access.read', 'throttle:nawasara-asn-portal-access'])
    ->name('access.show');
