<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Sistema de Agente (agentes humanos). Se carga dentro del grupo
            // `web` (sesión + CSRF) y no altera las rutas del chatbot.
            Route::middleware('web')->group(base_path('routes/agent.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        if (env('APP_ENV') === 'local') {
            $middleware->trustProxies(at: '*');
        }

        $middleware->alias([
            'agent' => App\Http\Middleware\EnsureUserIsAgent::class,
            'supervisor' => App\Http\Middleware\EnsureUserIsSupervisor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
