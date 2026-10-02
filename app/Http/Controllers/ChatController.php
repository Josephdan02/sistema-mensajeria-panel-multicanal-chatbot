<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Models\Conversation;
use App\Services\Chatbot\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Endpoints locales para probar el chatbot sin depender de WhatsApp.
 *
 * Este controller es deliberadamente delgado: solo valida la entrada, busca/
 * crea la Conversation, y delega TODA la lógica a ChatbotService. Cuando se
 * conecte el webhook real de WhatsApp, ese webhook hará básicamente lo mismo
 * (buscar/crear Conversation según el wa_id del remitente y llamar a
 * ChatbotService::handle()), reusando este mismo servicio.
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbotService)
    {
    }

    /**
     * Crea una conversación nueva y devuelve el saludo inicial.
     * Útil para simular "el usuario abrió el chat".
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'user_identifier' => ['required', 'string', 'max:255'],
        ]);

        $conversation = Conversation::create([
            'user_identifier' => $request->string('user_identifier'),
        ]);

        $result = $this->chatbotService->handle($conversation, 'hola');

        return response()->json([
            'conversation_id' => $result['conversation']->id,
            'message' => $result['message'],
            'intent' => $result['intent'],
        ]);
    }

    /**
     * Recibe un mensaje de un usuario y devuelve la respuesta del bot.
     *
     * Si no se envía conversation_id, se crea una conversación nueva usando
     * user_identifier (equivalente a que el usuario escriba por primera vez).
     */
    public function message(SendMessageRequest $request): JsonResponse
    {
        $conversation = null;

        try {
            $conversation = $request->filled('conversation_id')
                ? Conversation::findOrFail($request->integer('conversation_id'))
                : Conversation::create(['user_identifier' => $request->string('user_identifier')->toString()]);

            if ($request->filled('conversation_id')
                && $request->filled('user_identifier')
                && $conversation->user_identifier !== $request->string('user_identifier')->toString()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para acceder a esta conversación.',
                    'data' => [],
                ], 403);
            }

            $result = $this->chatbotService->handle($conversation, $request->string('message')->toString());

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'conversation_id' => $result['conversation']->id,
                    'intent' => $result['intent'],
                    // 'bot' o 'agent': cuando un agente humano controla la
                    // conversación el bot responde con mensaje vacío.
                    'handled_by' => $result['handled_by'] ?? 'bot',
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('Error procesando mensaje del chatbot.', [
                'conversation_id' => $conversation?->id,
                'awaiting' => $conversation?->getContextValue('awaiting'),
                'message' => Str::lower(Str::squish($request->string('message')->toString())),
                'exception' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No pudimos procesar tu mensaje. Inténtalo nuevamente.',
                'data' => [],
            ], 500);
        }
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Chatbot disponible.',
            'data' => ['service' => 'chatbot'],
        ]);
    }

    /**
     * Devuelve el historial de una conversación. Útil para depurar en las pruebas locales.
     */
    public function history(Conversation $conversation): JsonResponse
    {
        return response()->json([
            'conversation_id' => $conversation->id,
            'state' => $conversation->state->value,
            'context' => $conversation->context,
            'messages' => $conversation->messages()->orderBy('id')->get(
                ['sender', 'message', 'intent', 'created_at']
            ),
        ]);
    }
}