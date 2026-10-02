<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Services\External\Agenda\ApiAgendaService;
use Mockery\MockInterface;

/**
 * CONTACTOS + PERFIL + INBOX + PANEL DE CONTACTO.
 *
 * Los contactos se derivan de conversaciones existentes (sin tabla de
 * pacientes): CABANILLAS solo se consulta en vivo y nunca se copia en local.
 */
class AgentContactDirectoryTest extends AgentTestCase
{
    public function test_contacts_are_grouped_by_identifier_and_channel(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-ana',
            'channel' => 'web',
            'state' => ConversationState::IDLE,
        ]);
        $this->conversation([
            'user_identifier' => 'web-ana',
            'channel' => 'web',
            'state' => ConversationState::WAITING_AGENT,
        ]);
        $this->conversation([
            'user_identifier' => 'web-ana',
            'channel' => 'whatsapp',
            'state' => ConversationState::IDLE,
        ]);

        $response = $this->actingAs($agent)->get('/agent/contacts');

        $response->assertOk();
        $contacts = $response->viewData('contacts');

        $this->assertSame(2, $contacts->total());
        $this->assertSame(3, $contacts->items()[0]['conversations_count'] + $contacts->items()[1]['conversations_count']);
        $this->assertSame(3, Conversation::query()->count());
    }

    public function test_contacts_show_last_conversation_state_agent_and_last_activity(): void
    {
        $agent = $this->agent(['name' => 'Laura Agente']);

        $first = $this->conversation([
            'user_identifier' => 'web-historial',
            'state' => ConversationState::IDLE,
            'last_message_at' => now()->subDay(),
        ]);
        $last = $this->conversation([
            'user_identifier' => 'web-historial',
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
            'last_message_at' => now(),
        ]);
        $this->customerMessage($last, 'Mensaje pendiente');

        $response = $this->actingAs($agent)->get('/agent/contacts');

        $response->assertOk()
            ->assertSee('web-historial')
            ->assertSee('Laura Agente')
            ->assertSee('Asignada')
            ->assertSee('Abrir #'.$last->id)
            ->assertSee('>1<', false);

        $this->assertNotSame($first->id, $last->id);
    }

    public function test_contacts_search_matches_identifier_and_local_document(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-ana',
            'state' => ConversationState::IDLE,
            'context' => ['slots' => ['patient_document' => '12345678']],
        ]);
        $this->conversation([
            'user_identifier' => 'web-otro',
            'state' => ConversationState::IDLE,
        ]);

        $this->actingAs($agent)
            ->get('/agent/contacts?search=12345678')
            ->assertOk()
            ->assertSee('web-ana')
            ->assertDontSee('web-otro');

        $this->actingAs($agent)
            ->get('/agent/contacts?search=web-otro')
            ->assertOk()
            ->assertSee('web-otro')
            ->assertDontSee('web-ana');
    }

    public function test_contacts_show_clinical_unavailable_notice_when_the_api_fails(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-error',
            'state' => ConversationState::IDLE,
            'context' => ['slots' => ['patient_document' => '99999999']],
        ]);

        $this->mock(ApiAgendaService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('patientsByDocument')
                ->andThrow(\App\Services\External\Agenda\ClinicApiException::configuration('API caída.'));
        });

        $this->actingAs($agent)
            ->get('/agent/contacts')
            ->assertOk()
            ->assertSee('web-error')
            ->assertSee('99999999')
            ->assertSee('Datos clínicos no consultados');
    }
}
