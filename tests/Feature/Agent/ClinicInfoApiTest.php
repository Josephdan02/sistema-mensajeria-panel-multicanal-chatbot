<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

/**
 * Panel del contacto: los datos clínicos se consultan EN VIVO a CABANILLAS
 * mediante ApiAgendaService. No se guardan copias locales de pacientes ni citas.
 */
class ClinicInfoApiTest extends AgentTestCase
{
    public function test_contact_panel_shows_patient_data_queried_from_the_clinic_api(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-paciente',
            'state' => ConversationState::WAITING_AGENT,
            'context' => [
                'slots' => [
                    'paciente_id' => 12,
                    'patient_document' => '12345678',
                    'appointment_id' => 30,
                ],
            ],
        ]);

        $this->mock(ApiAgendaService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('patientsByDocument')
                ->once()
                ->with('12345678')
                ->andReturn([[
                    'id' => 12,
                    'tipo_documento' => 'DNI',
                    'numero_documento' => '12345678',
                    'nombres' => 'Ana',
                    'apellidos' => 'Pérez',
                    'telefono' => '999111222',
                    'email' => 'ana@cabanillas.test',
                ]]);

            $mock->shouldReceive('appointmentsByPatient')
                ->once()
                ->with(12)
                ->andReturn([[
                    'id' => 30,
                    'service' => 'Limpieza dental',
                    'professional' => 'Dra. Cabanillas',
                    'date' => '2026-10-01',
                    'time' => '09:00',
                    'status' => 'confirmed',
                    'status_original' => 'CONFIRMADA',
                ]]);

            $mock->shouldReceive('getAppointmentById')
                ->once()
                ->with(30)
                ->andReturn([
                    'id' => 30,
                    'service' => 'Limpieza dental',
                    'professional' => 'Dra. Cabanillas',
                    'date' => '2026-10-01',
                    'time' => '09:00',
                    'status' => 'confirmed',
                    'status_original' => 'CONFIRMADA',
                ]);
        });

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id)
            ->assertOk()
            ->assertSee('Ana')
            ->assertSee('Pérez')
            ->assertSee('12345678')
            ->assertSee('999111222')
            ->assertSee('ana@cabanillas.test')
            ->assertSee('Limpieza dental')
            ->assertSee('Dra. Cabanillas')
            ->assertSee('2026-10-01')
            ->assertSee('Confirmada');
    }

    public function test_contact_panel_handles_api_errors_gracefully(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-error',
            'state' => ConversationState::WAITING_AGENT,
            'context' => ['slots' => ['patient_document' => '99999999']],
        ]);

        $this->mock(ApiAgendaService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('patientsByDocument')
                ->andThrow(ClinicApiException::configuration('CLINIC_API_URL no está configurada.'));
        });

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id)
            ->assertOk()
            ->assertSee('No se pudo consultar la API clínica');
    }

    public function test_contact_panel_without_clinical_identifiers_does_not_call_the_api(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-sin-datos',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->mock(ApiAgendaService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('patientsByDocument');
            $mock->shouldNotReceive('appointmentsByPatient');
            $mock->shouldNotReceive('getAppointmentById');
        });

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id)
            ->assertOk()
            ->assertSee('todavía no identificó al paciente', false);
    }

    public function test_agent_system_does_not_store_clinic_entities_locally(): void
    {
        foreach (['pacientes', 'patients', 'citas', 'appointments', 'profesionales', 'servicios'] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "La tabla local '{$table}' no debería existir: CABANILLAS es la fuente de verdad.",
            );
        }
    }

    public function test_contact_list_is_derived_from_existing_conversations(): void
    {
        $agent = $this->agent();

        $this->conversation(['user_identifier' => 'web-11111111', 'state' => ConversationState::IDLE]);
        $this->conversation(['user_identifier' => 'web-11111111', 'state' => ConversationState::WAITING_AGENT]);
        $this->conversation(['user_identifier' => 'web-22222222', 'state' => ConversationState::CLOSED]);

        $response = $this->actingAs($agent)->get('/agent/contacts');

        $response->assertOk()
            ->assertSee('web-11111111')
            ->assertSee('web-22222222');

        $this->assertSame(3, \App\Models\Conversation::query()->count());
    }
}
