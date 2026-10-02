<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\Agent\ContactDirectoryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lista de contactos del sistema de agente.
 *
 * Los contactos se derivan de las conversaciones existentes (no se guarda
 * ninguna copia local de pacientes): el detalle clínico se consulta en vivo a
 * CABANILLAS. Toda la consulta vive en ContactDirectoryService.
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactDirectoryService $directory)
    {
    }

    public function index(Request $request): View
    {
        $search = trim($request->string('search')->toString());

        return view('agent.contacts.index', [
            'contacts' => $this->directory->paginate($search),
            'search' => $search,
        ]);
    }
}
