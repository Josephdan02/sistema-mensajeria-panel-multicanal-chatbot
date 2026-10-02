<?php

namespace App\Services\Agent;

use App\Models\Conversation;
use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Datos reales del paciente/cita para el panel del contacto.
 *
 * IMPORTANTE: el sistema de agente NO guarda copias locales de pacientes ni de
 * citas. Solo usa los identificadores que el chatbot ya dejó en
 * `conversations.context.slots` (paciente_id, patient_document, appointment_id)
 * para consultar CABANILLAS en vivo mediante ApiAgendaService (misma
 * configuración CLINIC_API_URL / CLINIC_API_KEY).
 */
class ClinicInfoService
{
    private const CACHE_TTL = 60;

    /**
     * Traducción de los estados ya normalizados por ApiAgendaService::mapStatus().
     * CABANILLAS sigue siendo la fuente de verdad: aquí solo se etiqueta.
     *
     * @var array<string, string>
     */
    public const APPOINTMENT_STATUS_LABELS = [
        'pending_confirmation' => 'Pendiente de confirmar',
        'confirmed' => 'Confirmada',
        'cancelled' => 'Cancelada',
        'rescheduled' => 'Reprogramada',
        'completed' => 'Atendida',
        'no_show' => 'No asistió',
        'in_progress' => 'En atención',
    ];

    public static function appointmentStatusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'Sin estado';
        }

        return self::APPOINTMENT_STATUS_LABELS[$status] ?? $status;
    }

    /** Nombre completo del paciente tal como lo devuelve CABANILLAS. */
    public static function patientFullName(?array $patient): ?string
    {
        if ($patient === null) {
            return null;
        }

        $name = trim(($patient['nombres'] ?? '').' '.($patient['apellidos'] ?? ''));

        return $name !== '' ? $name : null;
    }

    public function __construct(private readonly ApiAgendaService $agenda)
    {
    }

    /**
     * @return array{
     *     identifiers: array{patient_id: int|null, document: string|null, appointment_id: int|null},
     *     has_identifiers: bool,
     *     ok: bool,
     *     error: string|null,
     *     patient: array<string, mixed>|null,
     *     appointment: array<string, mixed>|null,
     *     appointments: array<int, array<string, mixed>>,
     *     upcoming: array<int, array<string, mixed>>,
     *     past: array<int, array<string, mixed>>
     * }
     */
    public function forConversation(Conversation $conversation): array
    {
        $identifiers = $conversation->clinicIdentifiers();
        $hasIdentifiers = $identifiers['patient_id'] !== null
            || $identifiers['document'] !== null
            || $identifiers['appointment_id'] !== null;

        $info = [
            'identifiers' => $identifiers,
            'has_identifiers' => $hasIdentifiers,
            'ok' => true,
            'error' => null,
            'patient' => null,
            'appointment' => null,
            'appointments' => [],
            'upcoming' => [],
            'past' => [],
        ];

        if (! $hasIdentifiers) {
            return $info;
        }

        try {
            if ($identifiers['document'] !== null) {
                $patients = $this->cache(
                    'paciente-doc-'.$identifiers['document'],
                    fn (): array => $this->agenda->patientsByDocument((string) $identifiers['document']),
                );

                $info['patient'] = is_array($patients) ? ($patients[0] ?? null) : null;
            }

            if ($identifiers['patient_id'] !== null) {
                $appointments = $this->cache(
                    'paciente-citas-'.$identifiers['patient_id'],
                    fn (): array => $this->agenda->appointmentsByPatient((int) $identifiers['patient_id']),
                );

                $info['appointments'] = is_array($appointments) ? $appointments : [];
            }

            if ($identifiers['appointment_id'] !== null) {
                $appointment = $this->cache(
                    'cita-'.$identifiers['appointment_id'],
                    fn (): ?array => $this->agenda->getAppointmentById((int) $identifiers['appointment_id']),
                );

                $info['appointment'] = is_array($appointment) ? $appointment : null;
            }
        } catch (ClinicApiException $exception) {
            $info['ok'] = false;
            $info['error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            $info['ok'] = false;
            $info['error'] = 'No se pudo consultar CABANILLAS en este momento.';
            Log::warning('No se pudo consultar la información clínica del contacto.', [
                'conversation_id' => $conversation->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        $split = $this->splitAppointments($info['appointments'], $info['appointment']);
        $info['upcoming'] = $split['upcoming'];
        $info['past'] = $split['past'];
        $info['appointment'] = $split['appointment'];

        return $info;
    }

    /**
     * Resumen del paciente para el directorio de contactos.
     *
     * Solo consulta el documento (una petición cacheada 60s por documento) para
     * no castigar a CABANILLAS con N peticiones por página.
     *
     * @return array{consulted: bool, ok: bool, error: string|null, patient: array<string, mixed>|null}
     */
    public function patientSummary(string $document): array
    {
        $summary = [
            'consulted' => true,
            'ok' => true,
            'error' => null,
            'patient' => null,
        ];

        try {
            $patients = $this->cache(
                'paciente-doc-'.$document,
                fn (): array => $this->agenda->patientsByDocument($document),
            );

            $summary['patient'] = is_array($patients) ? ($patients[0] ?? null) : null;
        } catch (ClinicApiException $exception) {
            $summary['ok'] = false;
            $summary['error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            $summary['ok'] = false;
            $summary['error'] = 'No se pudo consultar CABANILLAS en este momento.';
            Log::warning('No se pudo consultar el paciente en CABANILLAS.', [
                'document' => $document,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $summary;
    }

    /**
     * Separa las citas del paciente en próximas (ascendente) y pasadas
     * (descendente), añadiendo la etiqueta del estado. La fecha/hora se toma
     * tal cual la devuelve CABANILLAS: no se recalcula nada clínico.
     *
     * @param  array<int, array<string, mixed>>  $appointments
     * @param  array<string, mixed>|null  $contextAppointment
     * @return array{
     *     upcoming: array<int, array<string, mixed>>,
     *     past: array<int, array<string, mixed>>,
     *     appointment: array<string, mixed>|null
     * }
     */
    private function splitAppointments(array $appointments, ?array $contextAppointment): array
    {
        $today = Carbon::today();
        $upcoming = [];
        $past = [];

        foreach ($appointments as $appointment) {
            if (! is_array($appointment)) {
                continue;
            }

            $appointment['status_label'] = self::appointmentStatusLabel(
                is_scalar($appointment['status'] ?? null) ? (string) $appointment['status'] : null,
            );

            $date = $this->appointmentDate($appointment);

            if ($date !== null && $date->greaterThanOrEqualTo($today)) {
                $upcoming[] = $appointment;
            } else {
                $past[] = $appointment;
            }
        }

        usort($upcoming, fn (array $a, array $b): int => $this->sortKey($a) <=> $this->sortKey($b));
        usort($past, fn (array $a, array $b): int => $this->sortKey($b) <=> $this->sortKey($a));

        if ($contextAppointment !== null) {
            $contextAppointment['status_label'] = self::appointmentStatusLabel(
                is_scalar($contextAppointment['status'] ?? null) ? (string) $contextAppointment['status'] : null,
            );
        }

        return [
            'upcoming' => $upcoming,
            'past' => $past,
            'appointment' => $contextAppointment,
        ];
    }

    /** @param array<string, mixed> $appointment */
    private function sortKey(array $appointment): string
    {
        return (string) ($appointment['date'] ?? '').' '.(string) ($appointment['time'] ?? '');
    }

    /** @param array<string, mixed> $appointment */
    private function appointmentDate(array $appointment): ?Carbon
    {
        $date = $appointment['date'] ?? null;

        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($date));
        } catch (Throwable) {
            return null;
        }
    }

    private function cache(string $key, callable $callback): mixed
    {
        return Cache::remember('agent:clinic:'.$key, self::CACHE_TTL, $callback);
    }
}
