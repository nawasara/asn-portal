<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\MailController;

/*
|--------------------------------------------------------------------------
| Kisara Mail endpoints — /api/v1/asn-portal/mail/*
|--------------------------------------------------------------------------
| Behind api.staff.identity and api.client (only the Kisara Mail Keycloak
| client, config nawasara-asn-portal.mail_client_id). Identity is always
| the `sub` on the token.
*/

$address = '[^/]+';

Route::get('/mailboxes', [MailController::class, 'mailboxes'])->name('mailboxes');
Route::get('/directory', [MailController::class, 'directory'])->name('directory');
Route::post('/mailboxes/{address}/opened', [MailController::class, 'opened'])->where('address', $address)->name('mailboxes.opened');
Route::post('/mailboxes/{address}/pgp-events', [MailController::class, 'pgpEvent'])->where('address', $address)->name('mailboxes.pgp-events');
Route::post('/mailboxes/{address}/webmail', [MailController::class, 'webmail'])->where('address', $address)->name('mailboxes.webmail');
