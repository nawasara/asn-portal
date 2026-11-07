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

Route::get('/mailboxes', [MailController::class, 'mailboxes'])->name('mailboxes');
