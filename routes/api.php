<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// ━━━ X-Ray (O14) — Netflix-style actor info overlay ━━━
// Auth enforced inside XrayController (web session); browser players hit this with
// same-origin credentials so we use the `web` middleware group, not `auth:api`.
Route::middleware('web')->get('/xray/{movie}', [\App\Http\Controllers\XrayController::class, 'forMovie'])
    ->name('xray.forMovie');
