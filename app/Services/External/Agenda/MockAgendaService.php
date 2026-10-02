<?php

namespace App\Services\External\Agenda;

use App\Models\MockAppointment;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MockAgendaService implements AgendaServiceInterface
{
    public function checkAvailability(string $service, string $date): array
    {
        return trim($service) === '' || trim($date) === '' ? [] : [['time' => '09:00'], ['time' => '10:30'], ['time' => '16:00']];
    }

    public function getAppointment(string $userIdentifier): ?array
    {
        $appointment = MockAppointment::query()
            ->where('user_identifier', $userIdentifier)
            ->first();

        return $appointment?->toArray();
    }

    public function requestAppointment(string $userIdentifier, string $service, string $date, string $time): array
    {
        $appointment = MockAppointment::query()->create([
            'user_identifier' => $userIdentifier,
            'service' => $service,
            'date' => $date,
            'time' => $time,
            'status' => 'pending_confirmation',
        ]);

        return $appointment->toArray();
    }

    public function confirmAppointment(string $userIdentifier, int|string $appointmentId): ?array
    {
        $appointment = MockAppointment::query()
            ->where('user_identifier', $userIdentifier)
            ->whereKey($appointmentId)
            ->first();

        if ($appointment === null) {
            return null;
        }

        $appointment->update(['status' => 'confirmed']);

        return $appointment->fresh()->toArray();
    }

    public function cancelAppointment(string $userIdentifier, int|string $appointmentId): bool
    {
        $deleted = MockAppointment::query()
            ->where('user_identifier', $userIdentifier)
            ->whereKey($appointmentId)
            ->delete();

        return $deleted > 0;
    }

    public function rescheduleAppointment(
    string $userIdentifier,
    int|string $appointmentId,
    string $date,
    string $time
): ?array {
    $appointment = MockAppointment::query()
        ->where('user_identifier', $userIdentifier)
        ->whereKey($appointmentId)
        ->first();

    if ($appointment === null) {
        return null;
    }

    $appointment->update([
        'date' => $date,
        'time' => $time,
    ]);

    return $appointment->fresh()->toArray();
}

    public function appointmentsByPatient(int $patientId): array
    {
        // El mock no tiene el concepto de paciente: se mapea paciente_id al
        // user_identifier para que la operación siga siendo verificable en pruebas.
        return MockAppointment::query()
            ->where('user_identifier', (string) $patientId)
            ->get()
            ->map(fn (MockAppointment $appointment): array => $appointment->toArray())
            ->all();
    }
}
