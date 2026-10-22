<?php

namespace Tests\Unit;

use App\Services\Slots\RuleBasedServiceExtractor;
use PHPUnit\Framework\TestCase;

class RuleBasedServiceExtractorTest extends TestCase
{
    private RuleBasedServiceExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new RuleBasedServiceExtractor([
            'limpieza_dental' => [
                'name' => 'Limpieza dental',
                'aliases' => [
                    'limpieza dental',
                    'limpieza',
                    'limpiez dental',
                    'limpienza dental',
                    'limpiesa dental',
                ],
            ],
            'limpieza_dental_profunda' => [
                'name' => 'Limpieza dental profunda',
                'aliases' => ['limpieza dental profunda', 'limpieza profunda'],
            ],
            'extraccion_dental' => [
                'name' => 'Extracción dental',
                'aliases' => ['extracción dental', 'extraccion dental', 'extracción', 'extraccion'],
            ],
            'blanqueamiento_dental' => [
                'name' => 'Blanqueamiento dental',
                'aliases' => ['blanqueamiento dental', 'blanqueamiento'],
            ],
            'ortodoncia' => ['name' => 'Ortodoncia', 'aliases' => ['ortodoncia']],
            'endodoncia' => ['name' => 'Endodoncia', 'aliases' => ['endodoncia']],
            'implantes_dentales' => [
                'name' => 'Implantes dentales',
                'aliases' => ['implantes dentales', 'implantes'],
            ],
            'carillas_dentales' => [
                'name' => 'Carillas dentales',
                'aliases' => ['carillas dentales', 'carillas'],
            ],
            'odontopediatria' => [
                'name' => 'Odontopediatría',
                'aliases' => ['odontopediatría', 'odontopediatria', 'odontología infantil'],
            ],
            'protesis_dental' => [
                'name' => 'Prótesis dental',
                'aliases' => ['prótesis dental', 'protesis dental', 'prótesis', 'protesis'],
            ],
            'diseno_de_sonrisa' => [
                'name' => 'Diseño de sonrisa',
                'aliases' => ['diseño de sonrisa', 'diseno de sonrisa'],
            ],
            'cirugias_dentales' => [
                'name' => 'Cirugías dentales',
                'aliases' => ['cirugías dentales', 'cirugias dentales', 'cirugía dental', 'cirugia dental'],
            ],
        ]);
    }

    public function test_extracts_known_services_and_aliases(): void
    {
        $cases = [
            'Quiero una limpieza dental' => 'Limpieza dental',
            'Necesito limpieza' => 'Limpieza dental',
            'limpiez dental' => 'Limpieza dental',
            'LIMPIENZA   DENTAL' => 'Limpieza dental',
            'Limpiesa dental' => 'Limpieza dental',
            'extracción dental' => 'Extracción dental',
            'extraccion dental' => 'Extracción dental',
            'blanqueamiento' => 'Blanqueamiento dental',
            'Quiero ortodoncia' => 'Ortodoncia',
            'limpieza dental profunda' => 'Limpieza dental profunda',
            'LIMPIEZA   DENTAL' => 'Limpieza dental',
            'Necesito endodoncia' => 'Endodoncia',
            'implantes' => 'Implantes dentales',
            'carillas dentales' => 'Carillas dentales',
            'odontología infantil' => 'Odontopediatría',
            'protesis dental' => 'Prótesis dental',
            'diseño de sonrisa' => 'Diseño de sonrisa',
            'cirugia dental' => 'Cirugías dentales',
            'PRÓTESIS   DENTAL' => 'Prótesis dental',
            'CIRUGÍAS   DENTALES' => 'Cirugías dentales',
        ];

        foreach ($cases as $message => $expected) {
            $this->assertSame($expected, $this->extractor->extract($message), $message);
        }
    }

    public function test_does_not_extract_without_sufficient_service_evidence(): void
    {
        $this->assertNull($this->extractor->extract('dental'));
        $this->assertNull($this->extractor->extract('Quiero información sobre la clínica'));
        $this->assertNull($this->extractor->extract('Hola'));
        $this->assertNull($this->extractor->extract('Quiero una cita'));
    }

    public function test_returns_null_when_two_services_are_mentioned(): void
    {
        $this->assertNull($this->extractor->extract('Quiero limpieza dental o blanqueamiento'));
        $this->assertNull($this->extractor->extract('Quiero limpiez dental o blanqueamiento'));
        $this->assertSame('Limpieza dental profunda', $this->extractor->extract('Quiero limpieza dental profunda'));
        $this->assertSame('Limpieza dental', $this->extractor->extract('limpiez dental'));
        $this->assertNull($this->extractor->extract('carillas dentales y diseño de sonrisa'));
    }

    public function test_does_not_assign_an_unknown_treatment(): void
    {
        $this->assertNull($this->extractor->extract('Quiero una evaluación para una cirugía maxilofacial'));
        $this->assertNull($this->extractor->extract('limpiez'));
        $this->assertNull($this->extractor->extract('tratamiento dental especial'));
    }
}
