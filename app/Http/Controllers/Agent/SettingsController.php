<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\Agent\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Preferencias locales del agente (intervalo de polling y sonido).
 *
 * NO hay tabla de settings ni columnas nuevas: son preferencias de la
 * máquina/navegador y se guardan en localStorage desde la vista. Esta pantalla
 * solo documenta y ofrece el formulario; el JS existente lee/escribe las claves
 * `agent.pollingMs` y `agent.sound`. Sin infraestructura nueva a propósito.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly InboxService $inbox)
    {
    }

    public function index(Request $request): View
    {
        return view('agent.settings.index', [
            'stats' => $this->inbox->stats($request->user()),
        ]);
    }

    /** Resumen ligero para la campana del topbar (polling, sin WebSockets). */
    public function notifications(Request $request): JsonResponse
    {
        $stats = $this->inbox->stats($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'waiting' => $stats['waiting'],
                'mine' => $stats['mine'],
                'unread' => $stats['unread'],
                'unread_waiting' => $stats['unread_waiting'],
            ],
        ]);
    }
}
