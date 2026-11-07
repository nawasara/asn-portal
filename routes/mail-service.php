<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\MailServiceController;

/*
|--------------------------------------------------------------------------
| Kisara Mail server-to-server — /api/v1/asn-portal/mail-service/*
|--------------------------------------------------------------------------
| Called by the Kisara Mail SERVER with its own nws_ token (scope
| asn-portal.mail.holdings, IP allow-listed on the token), never by a
| browser. Used by the push watcher, which runs when nobody is signed in.
*/

Route::post('/holdings', [MailServiceController::class, 'holdings'])->name('holdings');
