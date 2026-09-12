<?php

namespace Tests\Unit;

use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
use App\Services\External\Agenda\ProfessionalResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfessionalResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
    }

    public function test_resolves_a_professional_with_or_without_title(): void
    {
        $this->fakeProfessionals([
            ['id' => 7, 'nombres' => 'Juan', 'apellidos' => 'Pérez'],
        ]);

        $result = $this->resolver()->resolve('Dr. Juan Pérez');

        $this->assertSame('found', $result['status']);
        $this->assertSame(7, $result['data']['id']);
    }

    public function test_returns_not_found_for_an_unknown_professional(): void
    {
        $this->fakeProfessionals([
            ['id' => 7, 'nombres' => 'Juan', 'apellidos' => 'Pérez'],
        ]);

        $this->assertSame('not_found', $this->resolver()->resolve('Ana Gómez')['status']);
    }

    public function test_does_not_choose_arbitrarily_between_matching_surnames(): void
    {
        $this->fakeProfessionals([
            ['id' => 7, 'nombres' => 'Juan', 'apellidos' => 'Pérez'],
            ['id' => 8, 'nombres' => 'Ana', 'apellidos' => 'Pérez'],
        ]);

        $result = $this->resolver()->resolve('Pérez');

        $this->assertSame('multiple_matches', $result['status']);
        $this->assertCount(2, $result['data']);
    }

    public function test_rejects_invalid_professional_catalog_json(): void
    {
        Http::fake(['http://clinic.test/api/profesionales' => Http::response(['data' => [['id' => 1]]])]);

        $this->expectException(ClinicApiException::class);
        $this->resolver()->resolve('Juan Pérez');
    }

    public function test_propagates_professional_catalog_api_errors(): void
    {
        Http::fake(['http://clinic.test/api/profesionales' => Http::response(['message' => 'down'], 503)]);

        $this->expectException(ClinicApiException::class);
        $this->resolver()->resolve('Juan Pérez');
    }

    private function fakeProfessionals(array $professionals): void
    {
        Http::fake(['http://clinic.test/api/profesionales' => Http::response(['data' => $professionals])]);
    }

    private function resolver(): ProfessionalResolver
    {
        return new ProfessionalResolver(new ApiAgendaService(app(Factory::class)));
    }
}
