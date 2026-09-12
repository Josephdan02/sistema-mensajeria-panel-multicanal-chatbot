<?php

namespace Tests\Unit;

use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
use App\Services\External\Agenda\ServiceResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
    }

    public function test_resolves_a_service_ignoring_case_accents_and_spaces(): void
    {
        $this->fakeServices([
            ['id' => 5, 'codigo' => 'LIMPIEZA', 'nombre' => 'Limpieza Dental'],
        ]);

        $result = $this->resolver()->resolve('  LIMPIEZA  DENTÁL ');

        $this->assertSame('found', $result['status']);
        $this->assertSame(5, $result['data']['id']);
        $this->assertSame('Limpieza Dental', $result['data']['nombre']);
    }

    public function test_returns_not_found_when_no_service_matches(): void
    {
        $this->fakeServices([
            ['id' => 5, 'codigo' => 'LIMPIEZA', 'nombre' => 'Limpieza Dental'],
        ]);

        $this->assertSame('not_found', $this->resolver()->resolve('implantes')['status']);
    }

    public function test_returns_multiple_matches_for_an_ambiguous_query(): void
    {
        $this->fakeServices([
            ['id' => 1, 'codigo' => 'CONSULTA_GENERAL', 'nombre' => 'Consulta general'],
            ['id' => 2, 'codigo' => 'CONSULTA_ODO', 'nombre' => 'Consulta odontológica'],
        ]);

        $result = $this->resolver()->resolve('consulta');

        $this->assertSame('multiple_matches', $result['status']);
        $this->assertCount(2, $result['data']);
    }

    public function test_rejects_invalid_catalog_json(): void
    {
        Http::fake(['http://clinic.test/api/servicios' => Http::response(['data' => ['id' => 1]])]);

        $this->expectException(ClinicApiException::class);
        $this->resolver()->resolve('limpieza dental');
    }

    public function test_propagates_catalog_api_errors(): void
    {
        Http::fake(['http://clinic.test/api/servicios' => Http::response(['message' => 'down'], 500)]);

        $this->expectException(ClinicApiException::class);
        $this->resolver()->resolve('limpieza dental');
    }

    private function fakeServices(array $services): void
    {
        Http::fake(['http://clinic.test/api/servicios' => Http::response(['data' => $services])]);
    }

    private function resolver(): ServiceResolver
    {
        return new ServiceResolver(new ApiAgendaService(app(Factory::class)));
    }
}
