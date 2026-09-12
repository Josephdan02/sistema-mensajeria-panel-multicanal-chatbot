<?php

namespace Tests\Unit;

use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
use App\Services\External\Agenda\PatientResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PatientResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
    }

    public function test_resolves_a_patient_by_document(): void
    {
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response([
            'data' => [
                'id' => 123,
                'tipo_documento' => 'DNI',
                'numero_documento' => '12345678',
                'nombres' => 'Juan',
                'apellidos' => 'Pérez',
            ],
        ])]);

        $result = $this->resolver()->resolve('12345678');

        $this->assertSame('found', $result['status']);
        $this->assertSame(123, $result['data']['id']);
        $this->assertSame('Juan', $result['data']['nombres']);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://clinic.test/api/pacientes?numero_documento=12345678'
            && $request->header('X-API-Key')[0] === 'test-api-key');
    }

    public function test_returns_not_found_for_a_missing_patient(): void
    {
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response(['message' => 'missing'], 404)]);

        $this->assertSame('not_found', $this->resolver()->resolve('12345678')['status']);
    }

    public function test_returns_multiple_matches_without_selecting_one(): void
    {
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response([
            'data' => [
                ['id' => 1, 'numero_documento' => '12345678', 'nombres' => 'Juan', 'apellidos' => 'Pérez'],
                ['id' => 2, 'numero_documento' => '12345678', 'nombres' => 'Juan', 'apellidos' => 'Pérez'],
            ],
        ])]);

        $result = $this->resolver()->resolve('12345678');

        $this->assertSame('multiple_matches', $result['status']);
        $this->assertCount(2, $result['data']);
    }

    public function test_does_not_call_the_api_for_an_empty_document(): void
    {
        Http::fake();

        $this->expectException(ClinicApiException::class);
        $this->resolver()->resolve('   ');
        Http::assertNothingSent();
    }

    public function test_api_errors_are_propagated_as_controlled_errors(): void
    {
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response(['message' => 'down'], 503)]);

        $this->expectException(ClinicApiException::class);
        $this->resolver()->resolve('12345678');
    }

    public function test_unexpected_patient_json_is_rejected(): void
    {
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response(['data' => ['id' => 1]])]);

        $this->expectException(ClinicApiException::class);
        $this->resolver()->resolve('12345678');
    }

    private function resolver(): PatientResolver
    {
        return new PatientResolver(new ApiAgendaService(app(Factory::class)));
    }
}
