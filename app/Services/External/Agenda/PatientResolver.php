<?php

namespace App\Services\External\Agenda;

class PatientResolver
{
    public function __construct(private readonly ApiAgendaService $agenda)
    {
    }

    public function resolve(string $numeroDocumento): array
    {
        $document = trim($numeroDocumento);

        if ($document === '') {
            throw ClinicApiException::unsupported('find_patient', 'El numero_documento es obligatorio.');
        }

        $patients = $this->agenda->patientsByDocument($document);

        return match (count($patients)) {
            0 => ['status' => 'not_found', 'data' => null],
            1 => ['status' => 'found', 'data' => $patients[0]],
            default => ['status' => 'multiple_matches', 'data' => $patients],
        };
    }
}
