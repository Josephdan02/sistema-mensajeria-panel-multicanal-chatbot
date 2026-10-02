<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/chat/health', [ChatController::class, 'health']);
Route::post('/chat', [ChatController::class, 'message']);

// ---------------------------------------------------------------------
// WhatsApp Cloud API (Meta). Sin middleware 'web' (sin CSRF: Meta no
// envía tokens de sesión) y con throttle para mitigar abuso.
// ---------------------------------------------------------------------
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:30,1');
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:60,1');