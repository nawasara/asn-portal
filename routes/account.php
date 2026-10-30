<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\AccountController;
use Nawasara\AsnPortal\Http\Api\MailboxController;
use Nawasara\AsnPortal\Http\Api\SiteController;
use Nawasara\AsnPortal\Http\Api\StorageController;
use Nawasara\AsnPortal\Http\Api\VpnController;
use Nawasara\AsnPortal\Http\Api\SecurityController;
use Nawasara\AsnPortal\Http\Api\WhatsappController;
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

// Actions that remove a way to sign in, sign other devices out, or change
// the recovery number need a FRESH login (auth_time), not just a valid one.
$recentAuth = 'api.recent-auth:'.(int) config('nawasara-asn-portal.recent_auth_seconds', 300);

Route::get('/profile', [SecurityController::class, 'profile'])->name('profile');
Route::get('/security', [SecurityController::class, 'security'])->name('security');
Route::get('/activity', [SecurityController::class, 'activity'])->name('activity');

Route::get('/credentials', [SecurityController::class, 'credentials'])->name('credentials.index');
Route::patch('/credentials/{id}', [SecurityController::class, 'renameCredential'])
    ->whereUuid('id')->name('credentials.rename');
Route::delete('/credentials/{id}', [SecurityController::class, 'removeCredential'])
    ->whereUuid('id')->middleware($recentAuth)->name('credentials.remove');

Route::get('/sessions', [SecurityController::class, 'sessions'])->name('sessions.index');
// Static before {id}.
Route::post('/sessions/logout-others', [SecurityController::class, 'revokeOtherSessions'])
    ->middleware($recentAuth)->name('sessions.logout-others');
// Not whereUuid: Keycloak's session id format has changed between versions.
// Ownership is checked against the caller's own sessions anyway.
Route::delete('/sessions/{id}', [SecurityController::class, 'revokeSession'])
    ->where('id', '[A-Za-z0-9._-]{8,128}')->name('sessions.revoke');

Route::post('/whatsapp', [WhatsappController::class, 'request'])->middleware($recentAuth)->name('whatsapp.request');
Route::post('/whatsapp/verify', [WhatsappController::class, 'verify'])->middleware($recentAuth)->name('whatsapp.verify');

// Official email. {address} is matched against the caller's own mailboxes
// in MailboxController; the pattern only keeps the path well-formed.
Route::prefix('mailboxes')->name('mailboxes.')->controller(MailboxController::class)->group(function () use ($recentAuth) {
    $address = '[A-Za-z0-9._%+@-]{3,190}';

    Route::get('/', 'index')->name('index');
    Route::post('/{address}/webmail', 'webmail')->where('address', $address)->name('webmail');
    Route::get('/{address}/access', 'access')->where('address', $address)->name('access');
    Route::put('/{address}/password', 'password')->where('address', $address)->middleware($recentAuth)->name('password');
    Route::get('/{address}/auto-reply', 'showAutoReply')->where('address', $address)->name('auto-reply.show');
    Route::put('/{address}/auto-reply', 'setAutoReply')->where('address', $address)->name('auto-reply.set');
    Route::delete('/{address}/auto-reply', 'clearAutoReply')->where('address', $address)->name('auto-reply.clear');
    Route::get('/{address}/forwarders', 'forwarders')->where('address', $address)->name('forwarders.index');
    Route::post('/{address}/forwarders', 'addForwarder')->where('address', $address)->middleware($recentAuth)->name('forwarders.add');
    Route::delete('/{address}/forwarders', 'removeForwarder')->where('address', $address)->name('forwarders.remove');
});

// VPN: the .conf carries the private key, so it needs a fresh login.
Route::get('/vpn', [VpnController::class, 'index'])->name('vpn.index');
Route::post('/vpn/{id}/config', [VpnController::class, 'config'])->whereNumber('id')->middleware($recentAuth)->name('vpn.config');
Route::post('/vpn/{id}/renew-request', [VpnController::class, 'requestRenewal'])->whereNumber('id')->name('vpn.renew-request');

Route::get('/sites', SiteController::class)->name('sites');
Route::get('/storage', StorageController::class)->name('storage');
