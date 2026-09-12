<?php

namespace App\Services\External\Agenda;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class ApiAgendaService implements AgendaServiceInterface
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(private readonly Factory $http)
    {
        $this->baseUrl = rtrim((string) config('services.clinic_api.url', ''), '/');
        $this->apiKey = trim((string) config('services.clinic_api.key', ''));
        $this->timeout = max(1, (int) config('services.clinic_api.timeout', 10));
    }

    public function checkAvailability(string $service, string $date): array
    {
        throw ClinicApiException::unsupported(
            'check_availability',
            'La disponibilidad real requiere servicio_id, profesional_id y una hora concreta.',
        );
    }

    public function getAppointment(string $userIdentifier): ?array
    {
        if (! ctype_digit($userIdentifier) || (int) $userIdentifier < 1) {
            throw ClinicApiException::unsupported(
                'get_appointment',
                'La API clínica requiere el cita_id; no permite buscar citas por userIdentifier.',
            );
        }

        return $this->getAppointmentById((int) $userIdentifier);
    }

    public function requestAppointment(string $userIdentifier, string $service, string $date, string $time): array
    {
        throw ClinicApiException::unsupported(
            'request_appointment',
            'La creación real requiere paciente_id, profesional_id y servicio_id.',
        );
    }

    public function confirmAppointment(string $userIdentifier, int|string $appointmentId): ?array
    {
        return $this->mutateAppointment(
            'confirm_appointment',
            (int) $appointmentId,
            fn (PendingRequest $client, int $id): Response => $client->post("api/citas/{$id}/confirmar"),
        );
    }

    public function cancelAppointment(string $userIdentifier, int|string $appointmentId): bool
    {
        $response = $this->send(
            'cancel_appointment',
            fn (PendingRequest $client): Response => $client->post(
                'api/citas/'.(int) $appointmentId.'/cancelar',
                [],
            ),
        );

        if ($response->status() === 404) {
            return false;
        }

        $this->appointmentPayload($response, 'cancel_appointment');

        return true;
    }

    public function rescheduleAppointment(
        string $userIdentifier,
        int|string $appointmentId,
        string $date,
        string $time,
    ): ?array {
        return $this->mutateAppointment(
            'reschedule_appointment',
            (int) $appointmentId,
            fn (PendingRequest $client, int $id): Response => $client->post(
                "api/citas/{$id}/reprogramar",
                ['fecha_hora_inicio' => $this->dateTime($date, $time)],
            ),
        );
    }

    public function getAppointmentById(int|string $appointmentId): ?array
    {
        $response = $this->send(
            'get_appointment',
            fn (PendingRequest $client): Response => $client->get('api/citas/'.(int) $appointmentId),
        );

        if ($response->status() === 404) {
            return null;
        }

        return $this->appointmentPayload($response, 'get_appointment');
    }

    public function checkSpecificAvailability(
        int $serviceId,
        int $professionalId,
        string $date,
        string $time,
        ?int $consultorioId = null,
    ): bool {
        $query = [
            'servicio_id' => $serviceId,
            'profesional_id' => $professionalId,
            'fecha' => $date,
            'hora' => $time,
        ];

        if ($consultorioId !== null) {
            $query['consultorio_id'] = $consultorioId;
        }

        $response = $this->send(
            'check_specific_availability',
            fn (PendingRequest $client): Response => $client->get('api/disponibilidad', $query),
        );
        $data = $this->dataPayload($response, 'check_specific_availability');

        if (! array_key_exists('available', $data) || ! is_bool($data['available'])) {
            throw new ClinicApiException(
                'La respuesta de disponibilidad no contiene un valor booleano válido.',
                $response->status(),
                'check_specific_availability',
            );
        }

        return $data['available'];
    }

    public function requestAppointmentByIds(
        int $patientId,
        int $professionalId,
        int $serviceId,
        string $date,
        string $time,
        ?int $consultorioId = null,
        ?string $motivo = null,
        ?string $observaciones = null,
    ): array {
        $payload = [
            'paciente_id' => $patientId,
            'profesional_id' => $professionalId,
            'servicio_id' => $serviceId,
            'fecha_hora_inicio' => $this->dateTime($date, $time),
        ];

        foreach (['consultorio_id' => $consultorioId, 'motivo' => $motivo, 'observaciones' => $observaciones] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        $response = $this->send(
            'request_appointment',
            fn (PendingRequest $client): Response => $client->post('api/citas', $payload),
        );

        if ($response->status() !== 201) {
            throw new ClinicApiException(
                'La API clínica no confirmó la creación de la cita con HTTP 201.',
                $response->status(),
                'request_appointment',
            );
        }

        return $this->appointmentPayload($response, 'request_appointment');
    }

    /** @return array<int, array{id: int, tipo_documento: string, numero_documento: string, nombres: string, apellidos: string}> */
    public function patientsByDocument(string $document): array
    {
        $response = $this->send(
            'find_patient',
            fn (PendingRequest $client): Response => $client->get('api/pacientes', [
                'numero_documento' => $document,
            ]),
        );

        if ($response->status() === 404) {
            return [];
        }

        $data = $this->dataPayload($response, 'find_patient');
        $patients = array_is_list($data) ? $data : [$data];

        return array_map(function (mixed $patient) use ($response): array {
            if (! is_array($patient) || ! isset($patient['id'], $patient['numero_documento'], $patient['nombres'], $patient['apellidos'])) {
                throw new ClinicApiException(
                    'La API clínica devolvió un paciente con datos incompletos.',
                    $response->status(),
                    'find_patient',
                );
            }

            return [
                'id' => (int) $patient['id'],
                'tipo_documento' => (string) ($patient['tipo_documento'] ?? ''),
                'numero_documento' => (string) $patient['numero_documento'],
                'nombres' => (string) $patient['nombres'],
                'apellidos' => (string) $patient['apellidos'],
            ];
        }, $patients);
    }

    /** @return array<int, array{id: int, codigo: string, nombre: string, descripcion?: string|null, duracion_estimada_minutos?: int, precio_actual?: string}> */
    public function activeServices(): array
    {
        return $this->catalog('services', 'api/servicios', ['id', 'codigo', 'nombre']);
    }

    /** @return array<int, array{id: int, nombres: string, apellidos: string, numero_documento?: string|null, numero_colegiatura?: string|null}> */
    public function activeProfessionals(): array
    {
        return $this->catalog('professionals', 'api/profesionales', ['id', 'nombres', 'apellidos']);
    }

    private function mutateAppointment(string $operation, int $appointmentId, callable $request): ?array
    {
        if ($appointmentId < 1) {
            throw ClinicApiException::unsupported($operation, 'El cita_id debe ser un entero positivo.');
        }

        $response = $this->send($operation, fn (PendingRequest $client): Response => $request($client, $appointmentId));

        if ($response->status() === 404) {
            return null;
        }

        return $this->appointmentPayload($response, $operation);
    }

    private function send(string $operation, callable $request): Response
    {
        $client = $this->client();

        try {
            $response = $request($client);
        } catch (ConnectionException $exception) {
            throw new ClinicApiException(
                'No fue posible conectarse con la API clínica.',
                null,
                $operation,
                $exception,
            );
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new ClinicApiException(
                $this->errorMessage($response, $operation),
                $response->status(),
                $operation,
            );
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        if ($this->baseUrl === '') {
            throw ClinicApiException::configuration('CLINIC_API_URL no está configurada.');
        }

        if ($this->apiKey === '') {
            throw ClinicApiException::configuration('CLINIC_API_KEY no está configurada.');
        }

        return $this->http
            ->baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->timeout);
    }

    private function appointmentPayload(Response $response, string $operation): array
    {
        $data = $this->dataPayload($response, $operation);

        if (! isset($data['id']) || ! is_int($data['id']) && ! ctype_digit((string) $data['id'])) {
            throw new ClinicApiException(
                'La respuesta de la API no contiene un cita_id válido.',
                $response->status(),
                $operation,
            );
        }

        $start = $data['fecha_hora_inicio'] ?? null;
        $service = is_array($data['servicio'] ?? null) ? $data['servicio'] : [];
        $status = is_array($data['estado'] ?? null) ? $data['estado'] : [];

        if (! is_string($start) || $start === '' || ! isset($service['nombre'], $status['codigo'])) {
            throw new ClinicApiException(
                'La respuesta de la API no contiene los datos mínimos de la cita.',
                $response->status(),
                $operation,
            );
        }

        $parsed = new \DateTimeImmutable($start);

        return [
            'id' => (int) $data['id'],
            'service' => (string) $service['nombre'],
            'date' => $data['fecha'] ?? $parsed->format('Y-m-d'),
            'time' => substr((string) ($data['hora'] ?? $parsed->format('H:i')), 0, 5),
            'status' => $this->mapStatus((string) $status['codigo']),
            'status_original' => (string) $status['codigo'],
        ];
    }

    private function dataPayload(Response $response, string $operation): array
    {
        $data = $response->json('data');

        if (! is_array($data)) {
            throw new ClinicApiException(
                'La API clínica devolvió una respuesta JSON inesperada.',
                $response->status(),
                $operation,
            );
        }

        return $data;
    }

    /** @param array<int, string> $required */
    private function catalog(string $operation, string $endpoint, array $required): array
    {
        $response = $this->send(
            'list_'.$operation,
            fn (PendingRequest $client): Response => $client->get($endpoint),
        );
        $data = $this->dataPayload($response, 'list_'.$operation);

        if (! array_is_list($data)) {
            throw new ClinicApiException(
                'La API clínica devolvió un catálogo con formato inesperado.',
                $response->status(),
                'list_'.$operation,
            );
        }

        foreach ($data as $item) {
            if (! is_array($item) || count(array_filter($required, fn (string $key): bool => array_key_exists($key, $item))) !== count($required)) {
                throw new ClinicApiException(
                    'La API clínica devolvió un catálogo con datos incompletos.',
                    $response->status(),
                    'list_'.$operation,
                );
            }
        }

        return array_map(fn (array $item): array => ['id' => (int) $item['id']] + $item, $data);
    }

    private function errorMessage(Response $response, string $operation): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== ''
            ? $message
            : "La API clínica devolvió HTTP {$response->status()} durante {$operation}.";
    }

    private function dateTime(string $date, string $time): string
    {
        return (new \DateTimeImmutable($date.' '.$time))->format('Y-m-d H:i:s');
    }

    private function mapStatus(string $status): string
    {
        return [
            'PENDIENTE' => 'pending_confirmation',
            'CONFIRMADA' => 'confirmed',
            'CANCELADA' => 'cancelled',
            'REPROGRAMADA' => 'rescheduled',
            'ATENDIDA' => 'completed',
            'NO_ASISTIO' => 'no_show',
            'EN_ATENCION' => 'in_progress',
        ][$status] ?? $status;
    }
}
