<?php

namespace App\Services\Agent;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Directorio de contactos del centro de agentes.
 *
 * Los contactos NO son una entidad propia ni una copia de los pacientes de
 * CABANILLAS: se derivan agrupando las conversaciones ya existentes en
 * `chatbot_clinica` por identificador + canal. El detalle clínico se consulta
 * en vivo con ClinicInfoService (misma API de CABANILLAS que usa el chatbot).
 *
 * Servicio de solo lectura: no crea ni modifica conversaciones.
 */
class ContactDirectoryService
{
    public const PER_PAGE = 20;

    /**
     * Consultas clínicas por petición.
     *
     * CABANILLAS es la fuente de verdad y es una API externa: se limita el
     * número de consultas por página (una por contacto con documento, cacheada
     * 60s) para no degradar el tiempo de respuesta ni castigar la API. Las
     * filas fuera del cupo muestran solo la información local disponible.
     */
    public const MAX_CLINIC_LOOKUPS = 10;

    public function __construct(private readonly ClinicInfoService $clinic)
    {
    }

    /**
     * @return LengthAwarePaginator<int, array{
     *     identifier: string,
     *     channel: string,
     *     conversations_count: int,
     *     waiting_count: int,
     *     last_activity_at: Carbon|null,
     *     local_document: string|null,
     *     conversation: Conversation|null,
     *     clinic: array<string, mixed>|null
     * }>
     */
    public function paginate(string $search, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $search = trim($search);
        $waitingStates = "'".implode("','", [
            ConversationState::HANDOFF_TO_HUMAN->value,
            ConversationState::WAITING_AGENT->value,
        ])."'";

        $paginator = Conversation::query()
            ->select([
                'user_identifier',
                'channel',
                DB::raw('COUNT(*) as conversations_count'),
                DB::raw('MAX(id) as last_conversation_id'),
                DB::raw('MAX(COALESCE(last_message_at, updated_at)) as last_activity_at'),
                DB::raw("SUM(CASE WHEN state IN ({$waitingStates}) THEN 1 ELSE 0 END) as waiting_count"),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                // El DNI lo guardó el chatbot dentro del contexto JSON de la
                // conversación (slots.patient_document): se busca ahí, sin crear
                // ninguna tabla de pacientes.
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('user_identifier', 'like', '%'.$search.'%')
                        ->orWhere('channel', 'like', '%'.$search.'%');

                    // PostgreSQL no define el operador LIKE para `json`; hay que
                    // convertir explícitamente la columna a texto. En MySQL y
                    // SQLite la comparación directa ya funciona.
                    if ($inner->getConnection()->getDriverName() === 'pgsql') {
                        $inner->orWhereRaw('CAST(context AS TEXT) ILIKE ?', ['%'.$search.'%']);
                    } else {
                        $inner->orWhere('context', 'like', '%'.$search.'%');
                    }
                });
            })
            ->groupBy('user_identifier', 'channel')
            ->orderByDesc('last_activity_at')
            ->paginate($perPage)
            ->withQueryString();

        $conversations = $this->latestConversations($paginator->items());
        $lookups = 0;
        $contacts = [];

        foreach ($paginator->items() as $row) {
            $conversation = $conversations->get((int) $row->last_conversation_id);
            $document = $conversation?->clinicIdentifiers()['document'] ?? null;

            $clinic = null;

            // Solo se consulta CABANILLAS si el chatbot ya capturó un documento
            // y queda cupo de consultas en esta página.
            if ($document !== null && $lookups < self::MAX_CLINIC_LOOKUPS) {
                $clinic = $this->clinic->patientSummary($document);
                $lookups++;
            }

            $contacts[] = [
                'identifier' => (string) $row->user_identifier,
                'channel' => (string) $row->channel,
                'conversations_count' => (int) $row->conversations_count,
                'waiting_count' => (int) $row->waiting_count,
                'last_activity_at' => $row->last_activity_at !== null
                    ? Carbon::parse((string) $row->last_activity_at)
                    : null,
                'local_document' => $document,
                'conversation' => $conversation,
                'clinic' => $clinic,
            ];
        }

        return $paginator->setCollection(collect($contacts));
    }

    /**
     * Última conversación de cada contacto de la página (con agente y no leídos).
     *
     * @param  array<int, object>  $rows
     * @return Collection<int, Conversation>
     */
    private function latestConversations(array $rows): Collection
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row->last_conversation_id ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return collect();
        }

        return Conversation::query()
            ->with(['assignedAgent:id,name', 'lastMessage'])
            ->withCount(['messages as unread_messages_count' => function (Builder $query): void {
                $query->where('sender', Message::SENDER_USER)->whereNull('read_at');
            }])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
