<?php

namespace App\Services\External\Agenda;

interface AgendaServiceInterface
{
    /** @return array<int, array{time: string}> */
    public function checkAvailability(string $service, string $date): array;
    /** @return array{id: int|string, service: string, date: string, time: string, status: string}|null */
    public function getAppointment(string $userIdentifier): ?array;
    /** @return array{id: int|string, service: string, date: string, time: string, status: string} */
    public function requestAppointment(string $userIdentifier, string $service, string $date, string $time): array;
    /** @return array{id: int|string, service: string, date: string, time: string, status: string}|null */
    public function confirmAppointment(string $userIdentifier, int|string $appointmentId): ?array;
    public function cancelAppointment(string $userIdentifier, int|string $appointmentId): bool;
    /** @return array{id: int|string, service: string, date: string, time: string, status: string} */
    public function rescheduleAppointment(string $userIdentifier, int|string $appointmentId, string $date, string $time): ?array;
    /** @return array<int, array{id: int|string, service: string, date: string, time: string, status: string}> */
    public function appointmentsByPatient(int $patientId): array;
}
