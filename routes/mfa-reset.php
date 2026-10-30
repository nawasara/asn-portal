<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\MfaResetController;

/*
|--------------------------------------------------------------------------
| MFA reset without signing in — /api/v1/asn-portal/public/mfa-reset/*
|--------------------------------------------------------------------------
| Called by the portal SERVER with its nws_ token (scope
| asn-portal.mfa.reset, IP allow-listed on the token), never by a browser.
| See MfaResetController for the full set of guards.
*/

Route::post('/request', [MfaResetController::class, 'request'])->name('request');
Route::post('/verify', [MfaResetController::class, 'verify'])->name('verify');
