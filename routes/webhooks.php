<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenFGA\Laravel\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| OpenFGA Webhook Routes
|--------------------------------------------------------------------------
|
| Here is where you can register webhook routes for your application.
| These routes are loaded by the WebhookServiceProvider.
|
*/

Route::post('/openfga/webhook', [WebhookController::class, 'handle'])
    ->name('openfga.webhook')
    ->middleware(['api']);