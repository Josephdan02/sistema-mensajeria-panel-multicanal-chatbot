<?php

namespace Tests\Unit;

use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiAgendaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
        config()->set('services.clinic_api.timeout', 7);
    }

    public function test_get_appointment_normalizes_the_clinic_response_and_sends_the_api_key(): void
    {
        Http::fake([
            'http://clinic.test/api/citas/123' => Http::response([
                'data' => $this->appointmentPayload('CONFIRMADA'),
            ], 200),
        ]);

        $appointment = $this->service()->getAppointment('123');

        $this->assertSame('confirmed', $appointment['status']);
        $this->assertSame('Limpieza dental', $appointment['service']);
        $this->assertSame('16:00', $appointment['time']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://clinic.test/api/citas/123'
                && $request->header('X-API-Key')[0] === 'test-api-key'
                && $request->header('Accept')[0] === 'application/json';
        });
    }

    public function test_get_appointment_returns_null_for_a_missing_appointment(): void
    {
        Http::fake(['http://clinic.test/api/citas/123' => Http::response(['message' => 'missing'], 404)]);

        $this->assertNull($this->service()->getAppointment('123'));
    }

    public function test_confirm_appointment_normalizes_the_confirmed_state(): void
    {
        Http::fake(['http://clinic.test/api/citas/123/confirmar' => Http::response([
            'data' => $this->appointmentPayload('CONFIRMADA'),
        ])]);

        $result = $this->service()->confirmAppointment('external-user', 123);

        $this->assertSame('confirmed', $result['status']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_cancel_appointment_returns_true_only_for_a_valid_success_response(): void
    {
        Http::fake(['http://clinic.test/api/citas/123/cancelar' => Http::response([
            'data' => $this->appointmentPayload('CANCELADA'),
        ])]);

        $this->assertTrue($this->service()->cancelAppointment('external-user', 123));
    }

    public function test_reschedule_sends_a_combined_datetime(): void
    {
        Http::fake(['http://clinic.test/api/citas/123/reprogramar' => Http::response([
            'data' => $this->appointmentPayload('PENDIENTE', '2026-09-12 16:00:00'),
        ])]);

        $this->service()->rescheduleAppointment('external-user', 123, '2026-09-12', '16:00');

        Http::assertSent(function ($request): bool {
            return $request->data()['fecha_hora_inicio'] === '2026-09-12 16:00:00';
        });
    }

    public function test_specific_availability_returns_the_api_boolean(): void
    {
        Http::fake(['http://clinic.test/api/disponibilidad*' => Http::response([
            'data' => ['available' => true],
        ])]);

        $this->assertTrue($this->service()->checkSpecificAvailability(2, 4, '2026-09-10', '16:00'));
    }

    public function test_request_appointment_by_ids_sends_the_real_payload(): void
    {
        Http::fake(['http://clinic.test/api/citas' => Http::response([
            'data' => $this->appointmentPayload('PENDIENTE'),
        ], 201)]);

        $result = $this->service()->requestAppointmentByIds(1, 4, 2, '2026-09-10', '16:00');

        $this->assertSame('pending_confirmation', $result['status']);
        Http::assertSent(function ($request): bool {
            return $request->data() === [
                'paciente_id' => 1,
                'profesional_id' => 4,
                'servicio_id' => 2,
                'fecha_hora_inicio' => '2026-09-10 16:00:00',
            ];
        });
    }

    public function test_current_contract_rejects_availability_without_a_professional_id(): void
    {
        $this->expectException(ClinicApiException::class);

        $this->service()->checkAvailability('Limpieza dental', '2026-09-10');
    }

    public function test_current_contract_rejects_creation_without_real_ids(): void
    {
        $this->expectException(ClinicApiException::class);

        $this->service()->requestAppointment('external-user', 'Limpieza dental', '2026-09-10', '16:00');
    }

    public function test_http_errors_never_return_a_successful_appointment(): void
    {
        Http::fake(['http://clinic.test/api/citas' => Http::response(['message' => 'conflict'], 409)]);

        $this->expectException(ClinicApiException::class);

        $this->service()->requestAppointmentByIds(1, 4, 2, '2026-09-10', '16:00');
    }

    #[DataProvider('apiErrorStatuses')]
    public function test_api_error_statuses_are_exposed_as_integration_errors(int $status): void
    {
        Http::fake(['http://clinic.test/api/citas' => Http::response(['message' => 'failure'], $status)]);

        $this->expectException(ClinicApiException::class);

        $this->service()->requestAppointmentByIds(1, 4, 2, '2026-09-10', '16:00');
    }

    public static function apiErrorStatuses(): array
    {
        return [[401], [404], [409], [422], [500], [503]];
    }

    public function test_unexpected_json_is_not_treated_as_a_success(): void
    {
        Http::fake(['http://clinic.test/api/citas/123' => Http::response(['ok' => true])]);

        $this->expectException(ClinicApiException::class);

        $this->service()->getAppointmentById(123);
    }

    public function test_missing_configuration_is_reported_before_a_request(): void
    {
        config()->set('services.clinic_api.key', '');

        $this->expectException(ClinicApiException::class);

        $this->service()->getAppointment('123');
    }

    public function test_connection_errors_are_wrapped_without_returning_a_success(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('timeout');
        });

        $this->expectException(ClinicApiException::class);

        $this->service()->getAppointmentById(123);
    }

    private function service(): ApiAgendaService
    {
        return new ApiAgendaService(app(Factory::class));
    }

    private function appointmentPayload(string $status, string $start = '2026-09-10 16:00:00'): array
    {
        return [
            'id' => 123,
            'servicio' => ['id' => 2, 'nombre' => 'Limpieza dental'],
            'fecha' => substr($start, 0, 10),
            'hora' => substr($start, 11, 8),
            'fecha_hora_inicio' => $start,
            'estado' => ['id' => 1, 'codigo' => $status, 'nombre' => $status],
        ];
    }
}
