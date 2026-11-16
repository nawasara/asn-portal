<?php

use Illuminate\Support\Facades\Route;
use Nawasara\AsnPortal\Http\Api\LauncherController;

/*
|--------------------------------------------------------------------------
| Shared Kisara endpoints: /api/v1/kisara/*
|--------------------------------------------------------------------------
| Called by the servers of Akun Kisara, Kisara Mail and Kisara Workspace
| with their own nws_ tokens (scope asn-portal.launcher.read), never by a
| browser. See LauncherController.
*/

Route::get('/launcher', [LauncherController::class, 'index'])->name('launcher');
