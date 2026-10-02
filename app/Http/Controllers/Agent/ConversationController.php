<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AssignConversationRequest;
use App\Http\Requests\Agent\ReplyMessageRequest;
use App\Http\Requests\Agent\UpdateConversationStateRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Agent\ClinicInfoService;
use App\Services\Agent\ConversationHandoffService;
use App\Services\Agent\InboxService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bandeja de conversaciones y vista individual del agente.
 *
 * El controller no contiene lógica de negocio: delega en InboxService
 * (consultas), ConversationHandoffService (estados/mensajes del agente) y
 * ClinicInfoService (datos reales de CABANILLAS).
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly InboxService $inbox,
        private readonly ConversationHandoffService $handoff,
        private readonly ClinicInfoService $clinic,
    ) {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Conversation::class);

        $filter = $this->resolveFilter($request->string('filter')->toString());
        $search = $request->string('search')->toString();

        return view('agent.conversations.index', [
            'conversations' => $this->inbox->list($filter, $search, $request->user()),
            'filter' => $filter,
            'search' => $search,
            'filters' => InboxService::filters(),
            'stats' => $this->inbox->stats($request->user()),
        ]);
    }

    /** Listado lateral (HTML parcial) que el polling reemplaza en vivo. */
    public function sidebar(Request $request): View
    {
        Gate::authorize('viewAny', Conversation::class);

        $filter = $this->resolveFilter($request->string('filter')->toString());

        return view('agent.conversations._list', [
            'conversations' => $this->inbox->list($filter, $request->string('search')->toString(), $request->user(), 30),
            'filter' => $filter,
            'activeId' => $request->integer('active') ?: null,
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $filter = $this->resolveFilter($request->string('filter')->toString(), InboxService::FILTER_OPEN);
        $unreadBefore = $conversation->unreadMessagesCount();

        // Abrir la conversación equivale a leerla por parte del agente.
        $this->handoff->markAsRead($conversation);

        return view('agent.conversations.show', [
            'conversation' => $conversation->refresh()->load('assignedAgent:id,name'),
            'messages' => $this->threadMessages($conversation),
            'agents' => $this->agentOptions(),
            'clinic' => $this->clinic->forConversation($conversation),
            'filter' => $filter,
            'filters' => InboxService::filters(),
            'conversations' => $this->inbox->list($filter, '', $request->user(), 30),
            'unreadBefore' => $unreadBefore,
        ]);
    }

    /** Hilo de mensajes (HTML parcial) para el refresco en vivo. */
    public function thread(Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $this->handoff->markAsRead($conversation);

        return view('agent.conversations._thread', [
            'conversation' => $conversation->refresh(),
            'messages' => $this->threadMessages($conversation),
        ]);
    }

    public function reply(ReplyMessageRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $this->handoff->reply($conversation, $request->user(), $request->string('message')->toString());

        return $this->respond($request, $conversation, 'Mensaje enviado al cliente.');
    }

    /** El agente toma la conversación (queda asignada y en atención pendiente). */
    public function take(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        Gate::authorize('take', $conversation);

        $this->handoff->claim($conversation, $request->user());

        return $this->respond($request, $conversation, 'Tomaste la conversación.');
    }

    /** Asigna la conversación a un agente concreto. */
    public function assign(AssignConversationRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        Gate::authorize('assign', $conversation);

        $target = User::query()->findOrFail($request->integer('agent_id'));

        $this->handoff->assign($conversation, $target, $request->user());

        return $this->respond($request, $conversation, 'Conversación asignada a '.$target->name.'.');
    }

    /** Cambio manual de estado. */
    public function updateState(UpdateConversationStateRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        Gate::authorize('updateState', $conversation);

        $state = $request->state();
        $note = $request->string('note')->toString();

        $this->handoff->changeState($conversation, $state, $request->user(), $note !== '' ? $note : null);

        return $this->respond($request, $conversation, 'Estado actualizado a «'.$state->label().'».');
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        Gate::authorize('close', $conversation);

        $this->handoff->close($conversation, $request->user());

        return $this->respond($request, $conversation, 'Conversación cerrada.');
    }

    /** Devuelve el control al bot. */
    public function releaseToBot(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        Gate::authorize('updateState', $conversation);

        $this->handoff->releaseToBot($conversation, $request->user());

        return $this->respond($request, $conversation, 'La conversación volvió al bot.');
    }

    /** Devuelve la conversación a la cola de agentes. */
    public function returnToQueue(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        Gate::authorize('updateState', $conversation);

        $this->handoff->returnToQueue($conversation, $request->user());

        return $this->respond($request, $conversation, 'La conversación volvió a la cola de agentes.');
    }

    /**
     * Respuesta uniforme para las acciones: JSON cuando se llama por fetch
     * (polling/JS) y redirección normal cuando se envía un formulario.
     */
    private function respond(Request $request, Conversation $conversation, string $status): RedirectResponse|JsonResponse
    {
        $conversation->refresh();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $status,
                'data' => [
                    'conversation_id' => $conversation->id,
                    'state' => $conversation->state->value,
                    'state_label' => $conversation->state->label(),
                    'assigned_agent_id' => $conversation->assigned_agent_id,
                ],
            ]);
        }

        $parameters = ['conversation' => $conversation->id];

        if ($request->filled('filter')) {
            $parameters['filter'] = $request->string('filter')->toString();
        }

        return redirect()->route('agent.conversations.show', $parameters)->with('status', $status);
    }

    private function resolveFilter(string $filter, string $default = InboxService::FILTER_WAITING): string
    {
        return InboxService::isValidFilter($filter) ? $filter : $default;
    }

    /**
     * Últimos 200 mensajes en orden cronológico.
     */
    private function threadMessages(Conversation $conversation)
    {
        return $conversation->messages()
            ->with('agent:id,name')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Agentes activos para el selector de asignación.
     */
    private function agentOptions()
    {
        return User::query()
            ->agents()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

}
