<?php

use App\Http\Controllers\Agent\AttachmentController;
use App\Http\Controllers\Agent\ContactController;
use App\Http\Controllers\Agent\ConversationController;
use App\Http\Controllers\Agent\DashboardController;
use App\Http\Controllers\Agent\LoginController;
use App\Http\Controllers\Agent\ProfileController;
use App\Http\Controllers\Agent\SettingsController;
use App\Http\Controllers\Agent\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Sistema de Agente
|--------------------------------------------------------------------------
|
| Se registran desde bootstrap/app.php dentro del grupo `web` (sesión + CSRF),
| de modo que routes/web.php y routes/api.php (chatbot) quedan intactos.
|
| IMPORTANTE: el grupo autenticado usa únicamente el middleware `agent`
| (EnsureUserIsAgent), que redirige a /agent/login cuando no hay sesión.
|
*/

Route::prefix('agent')->name('agent.')->group(function (): void {
    // --- Autenticación de agentes -----------------------------------------
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');

    // --- Área autenticada (rol agente/supervisor) -------------------------
    Route::middleware('agent')->group(function (): void {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');

        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/sidebar', [ConversationController::class, 'sidebar'])->name('conversations.sidebar');

        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
            ->whereNumber('conversation')->name('conversations.show');
        Route::get('conversations/{conversation}/thread', [ConversationController::class, 'thread'])
            ->whereNumber('conversation')->name('conversations.thread');
        Route::post('conversations/{conversation}/reply', [ConversationController::class, 'reply'])
            ->whereNumber('conversation')->name('conversations.reply');
        Route::post('conversations/{conversation}/take', [ConversationController::class, 'take'])
            ->whereNumber('conversation')->name('conversations.take');
        Route::post('conversations/{conversation}/assign', [ConversationController::class, 'assign'])
            ->whereNumber('conversation')->name('conversations.assign');
        Route::post('conversations/{conversation}/state', [ConversationController::class, 'updateState'])
            ->whereNumber('conversation')->name('conversations.state');
        Route::post('conversations/{conversation}/close', [ConversationController::class, 'close'])
            ->whereNumber('conversation')->name('conversations.close');
        Route::post('conversations/{conversation}/release-to-bot', [ConversationController::class, 'releaseToBot'])
            ->whereNumber('conversation')->name('conversations.release');
        Route::post('conversations/{conversation}/return-to-queue', [ConversationController::class, 'returnToQueue'])
            ->whereNumber('conversation')->name('conversations.queue');

        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');

        // Adjuntos de los canales externos (Telegram): siempre dentro del
        // grupo `agent` (sesión + rol) y con autorización por conversación
        // (AttachmentPolicy). El archivo nunca se expone por ruta interna.
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])
            ->whereNumber('attachment')->name('attachments.show');
        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
            ->whereNumber('attachment')->name('attachments.download');

        Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // Preferencias locales + resumen ligero para la campana (polling).
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::get('notifications/summary', [SettingsController::class, 'notifications'])->name('notifications.summary');

        // Administración de usuarios: solo supervisores (doble capa:
        // middleware `supervisor` + UserPolicy en cada acción).
        Route::middleware('supervisor')->group(function (): void {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('users/{user}/toggle', [UserController::class, 'toggleActive'])->name('users.toggle');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        });
    });
});
