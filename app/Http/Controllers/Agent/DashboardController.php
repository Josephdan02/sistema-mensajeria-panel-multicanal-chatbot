<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\Agent\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard del agente: colas de trabajo y contadores.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly InboxService $inbox)
    {
    }

    public function index(Request $request): View
    {
        $agent = $request->user();

        return view('agent.dashboard', [
            'stats' => $this->inbox->stats($agent),
            'waiting' => $this->inbox->waiting(5),
            'mine' => $this->inbox->mine($agent, 5),
        ]);
    }

    /** Contadores para el refresco en vivo (polling). */
    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->inbox->stats($request->user()),
        ]);
    }
}
