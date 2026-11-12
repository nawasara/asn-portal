<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\StaffEmailController;

/*
|--------------------------------------------------------------------------
| Staff data pushed by SIMAS Hebat — /api/v1/asn-portal/staff/*
|--------------------------------------------------------------------------
| Called by the SIMAS Hebat SERVER with its own nws_ token (scope
| asn-portal.staff.email.write, IP allow-listed on the token), never by a
| browser. See StaffEmailController for the guards.
*/

// No `where` on {nip}: a malformed NIP gets the controller's 422 with a
// reason, not a bare 404 that looks like a wrong URL.
Route::put('/{nip}/email', [StaffEmailController::class, 'update'])->name('email.update');
