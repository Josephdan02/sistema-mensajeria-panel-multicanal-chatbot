<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationState;
use App\Enums\Intent;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\External\AI\AIServiceInterface;
use App\Services\External\Agenda\AgendaServiceInterface;
use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
use App\Services\External\Agenda\PatientResolver;
use App\Services\External\Agenda\ProfessionalResolver;
use App\Services\External\Agenda\ServiceResolver;
use App\Services\Intent\IntentDetectorInterface;
use App\Services\Response\ResponseGenerator;
use App\Services\Slots\ServiceExtractorInterface;
use App\Services\Slots\DateExtractorInterface;
use App\Services\Slots\TimeExtractorInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestador central del chatbot.
 *
 * Flujo (ver diagrama en el README):
 *   Mensaje recibido -> [Controller] -> ChatbotService::handle()
 *     -> IntentDetector (si aplica)
 *     -> ConversationManager (estado / contexto)
 *     -> Lógica de negocio (aquí mismo, o AgendaService para citas)
 *     -> ResponseGenerator
 *     -> Persistencia (Message, Conversation)
 *
 * Esta clase NO sabe nada de WhatsApp ni de HTTP: solo recibe una Conversation
 * y un texto, y devuelve una respuesta de texto + metadata. Eso permite
 * reusarla exactamente igual desde el endpoint local de pruebas y, más
 * adelante, desde el webhook de WhatsApp.
 */
class ChatbotService
{
    public function __construct(
        private readonly IntentDetectorInterface $intentDetector,
        private readonly ConversationManager $conversationManager,
        private readonly ResponseGenerator $responses,
        private readonly AgendaServiceInterface $agendaService,
        private readonly AIServiceInterface $aiService,
        private readonly ServiceExtractorInterface $serviceExtractor,
        private readonly DateExtractorInterface $dateExtractor,
        private readonly TimeExtractorInterface $timeExtractor,
        private readonly PatientResolver $patientResolver,
        private readonly ServiceResolver $serviceResolver,
        private readonly ProfessionalResolver $professionalResolver,
    ) {
    }

    /**
     * @return array{conversation: Conversation, message: string, intent: string}
     */
    public function handle(Conversation $conversation, string $userMessage): array
    {
        $this->conversationManager->reopenIfClosed($conversation);

        if ($conversation->state === ConversationState::HANDOFF_TO_HUMAN) {
            return $this->respondWhileInHandoff($conversation, $userMessage);
        }
        // Sistema de agentes: si un agente humano ya tiene el control
        // (asignada o en atención) el bot NO responde automáticamente; solo se
        // registra el mensaje del cliente para que lo vea el agente.
        // Mismo comportamiento cuando la conversación está en la cola de
        // espera (WAITING_AGENT): el bot no interpreta intenciones ni responde,
        // solo persiste el mensaje como no leído para el agente.
        if ($conversation->state?->isHumanControlled() === true
            || $conversation->state === ConversationState::WAITING_AGENT) {
            return $this->recordCustomerMessageWhileAgentControls($conversation, $userMessage);
        }



        $intent = $this->intentDetector->detect($userMessage, $conversation);
        $service = $this->serviceExtractor->extract($userMessage);
        $awaitingInput = $this->conversationManager->isAwaitingInput($conversation);
        $invalidExtractedDate = false;
        $invalidExtractedTime = false;

        if ($service !== null) {
            $this->conversationManager->setSlot($conversation, 'service', $service);
        }

        if (! $awaitingInput && $intent === Intent::REQUEST_APPOINTMENT) {
            $document = $this->extractDocument($userMessage);

            if ($document !== null) {
                $this->conversationManager->setSlot($conversation, 'patient_document', $document);
            }

            $date = $this->dateExtractor->extract($userMessage);

            if ($date !== null) {
                if ($this->conversationManager->isValidDate($date)) {
                    $this->conversationManager->setSlot($conversation, 'date', $date);
                } else {
                    $invalidExtractedDate = true;
                }
            }

            $time = $this->timeExtractor->extract($userMessage);

            if ($time !== null) {
                if ($this->conversationManager->isValidTime($time)) {
                    $this->conversationManager->setSlot($conversation, 'time', $time);
                } else {
                    $invalidExtractedTime = true;
                }
            }
        }

        // Una petición de hablar con un humano siempre tiene prioridad, incluso
        // en medio de otro flujo (ej. agendando una cita).
        if ($intent === Intent::HUMAN_HANDOFF) {
            return $this->escalateToHuman($conversation, $userMessage, $intent);
        }

        // Comandos globales (hola, salir, menú, cancelar, registrarme...): se
        // evalúan ANTES de continueFlow() para que un comando nunca sea
        // interpretado como el dato que el flujo está esperando.
        $protectedAwaiting = $awaitingInput && $this->isProtectedAwaiting($conversation);

        if (! $protectedAwaiting) {
            $command = $this->detectGlobalCommand($userMessage, $awaitingInput);

            if ($command !== null) {
                return $this->runGlobalCommand($conversation, $userMessage, $command);
            }
        }

        if ($awaitingInput
            && ! $protectedAwaiting
            && $this->isFlowInterruption($intent, $userMessage)) {
            return $this->interruptFlow($conversation, $userMessage, $intent);
        }

        if ($awaitingInput) {
            return $this->continueFlow($conversation, $userMessage, $intent);
        }

        $this->conversationManager->setSlot($conversation, 'intent', $intent->value);

        if ($invalidExtractedDate && $service !== null) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_date');

            return $this->persist($conversation, $userMessage, $intent, $this->responses->invalidDate());
        }

        if ($invalidExtractedTime && $service !== null) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_time');

            return $this->persist($conversation, $userMessage, $intent, $this->responses->invalidTime());
        }

        return $this->startNewExchange($conversation, $userMessage, $intent);
    }

    /**
     * No hay un flujo en curso: se procesa la intención detectada desde cero.
     */
    private function startNewExchange(Conversation $conversation, string $userMessage, Intent $intent): array
    {
        $reply = match ($intent) {
            Intent::GREETING => $this->responses->greeting(),
            Intent::FAREWELL => $this->closeConversation($conversation),
            Intent::GENERAL_INFO => $this->askWhichServiceForInfo($conversation),
            Intent::SERVICES => $this->responses->services(),
            Intent::PRICING => $this->responses->pricing(),
            Intent::SCHEDULE => $this->responses->schedule(),
            Intent::LOCATION => $this->responses->location(),
            Intent::CONTACT => $this->responses->contact(),
            Intent::REQUEST_APPOINTMENT => $this->startAppointmentRequest($conversation),
            Intent::AVAILABILITY => $this->startAvailabilityRequest($conversation, $userMessage),
            Intent::CHECK_APPOINTMENT => $this->checkAppointment($conversation),
            Intent::CANCEL_APPOINTMENT => $this->cancelAppointment($conversation),
            Intent::RESCHEDULE_APPOINTMENT => $this->startReschedule($conversation),
            default => $this->unknownResponse($conversation, $userMessage),
        };

        return $this->persist($conversation, $userMessage, $intent, $reply);
    }

    /**
     * Hay una pregunta pendiente (context['awaiting']); el mensaje del usuario
     * se interpreta como la respuesta a esa pregunta, no como una intención nueva.
     */
    private function continueFlow(Conversation $conversation, string $userMessage, Intent $intent): array
    {
        $awaiting = $this->conversationManager->getAwaiting($conversation);
        $storedIntent = $this->conversationManager->getSlot($conversation, 'intent');
        $effectiveIntent = is_string($storedIntent)
            ? (Intent::tryFrom($storedIntent) ?? $intent)
            : $intent;

        $reply = match ($awaiting) {
            'service_info' => $this->responses->serviceInfo(trim($userMessage)),
            'patient_document' => $this->onPatientDocumentProvided($conversation, $userMessage),
            'appointment_service' => $this->onAppointmentServiceProvided($conversation, $userMessage),
            'appointment_professional' => $this->onAppointmentProfessionalProvided($conversation, $userMessage),
            'appointment_date' => $this->onAppointmentDateProvided($conversation, $userMessage),
            'appointment_time' => $this->onAppointmentTimeProvided($conversation, $userMessage),
            'appointment_confirmation' => $this->onAppointmentConfirmationProvided($conversation, $userMessage),
            'availability_service' => $this->onAvailabilityServiceProvided($conversation, $userMessage),
            'availability_date' => $this->onAvailabilityDateProvided($conversation, $userMessage),
            'reschedule_date' => $this->onRescheduleDateProvided($conversation, $userMessage),
            'reschedule_time' => $this->onRescheduleTimeProvided($conversation, $userMessage),
            'appointment_query_document' => $this->onQueryDocumentProvided($conversation, $userMessage),
            'cancel_query_document' => $this->onCancelDocumentProvided($conversation, $userMessage),
            'reschedule_query_document' => $this->onRescheduleDocumentProvided($conversation, $userMessage),
            'appointment_selection' => $this->onAppointmentSelected($conversation, $userMessage),
            'cancel_confirmation' => $this->onCancelConfirmationProvided($conversation, $userMessage),
            default => $this->responses->unknown(),
        };

        if ($awaiting === 'service_info') {
            $this->conversationManager->finishFlow($conversation);
        }

        return $this->persist($conversation, $userMessage, $effectiveIntent, $reply);
    }

    private function isFlowInterruption(Intent $intent, string $userMessage): bool
    {
        if (in_array($intent, [
            Intent::CHECK_APPOINTMENT,
            Intent::CANCEL_APPOINTMENT,
            Intent::RESCHEDULE_APPOINTMENT,
            Intent::REQUEST_APPOINTMENT,
        ], true)) {
            return true;
        }

        if (in_array($intent, [Intent::SERVICES, Intent::AVAILABILITY], true)) {
            // Un número suelto puede ser la opción elegida dentro del flujo en
            // curso (ej. "5" para un servicio), así que no interrumpe.
            return preg_match('/\A\d+\z/', trim($userMessage)) !== 1;
        }

        return false;
    }

    /**
     * Comandos que deben tener prioridad sobre el dato que el flujo espera.
     *
     * Devuelve null cuando el mensaje no es un comando global; en ese caso el
     * mensaje se interpreta normalmente (respuesta del flujo o intención nueva).
     *
     * @return string|null exit|cancel|register|greeting|menu
     */
    private function detectGlobalCommand(string $userMessage, bool $awaitingInput): ?string
    {
        $normalized = $this->normalizeGlobalCommand($userMessage);

        if (preg_match('/\bregistr(?:ame|arme|ate|ar|o|amos|arse)\b/u', $normalized) === 1) {
            return 'register';
        }

        if (in_array($normalized, ['salir', 'exit', 'quit', 'salir del chat', 'terminar', 'terminar chat'], true)) {
            return 'exit';
        }

        if (in_array($normalized, ['menu', 'menú', 'reiniciar', 'empezar de nuevo', 'comenzar de nuevo', 'inicio', 'ayuda', 'opciones'], true)) {
            return 'menu';
        }

        if (in_array($normalized, ['cancelar', 'cancelar todo', 'cancelar el proceso', 'olvidalo', 'olvídalo'], true)) {
            return 'cancel';
        }

        // El saludo solo interrumpe cuando hay una pregunta pendiente: en estado
        // libre ya lo atiende startNewExchange() como intención GREETING.
        if ($awaitingInput && in_array($normalized, [
            'hola', 'buenas', 'hey', 'saludos',
            'buenos dias', 'buenos días', 'buenas tardes', 'buenas noches', 'buen dia', 'buen día',
        ], true)) {
            return 'greeting';
        }

        return null;
    }

    /**
     * @return array{conversation: Conversation, message: string, intent: string}
     */
    private function runGlobalCommand(Conversation $conversation, string $userMessage, string $command): array
    {
        if ($command === 'exit') {
            return $this->persist($conversation, $userMessage, Intent::FAREWELL, $this->closeConversation($conversation));
        }

        if ($command === 'register') {
            $this->conversationManager->finishFlow($conversation);

            return $this->persist($conversation, $userMessage, Intent::UNKNOWN, $this->responses->patientRegistrationUnavailable());
        }

        if ($command === 'cancel') {
            $this->conversationManager->finishFlow($conversation);

            return $this->persist($conversation, $userMessage, Intent::UNKNOWN, $this->responses->flowCancelled());
        }

        // 'greeting' y 'menu' abandonan el flujo en curso y muestran el menú.
        $this->conversationManager->finishFlow($conversation);

        return $this->persist($conversation, $userMessage, Intent::GREETING, $this->responses->greeting());
    }

    private function normalizeGlobalCommand(string $message): string
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized, " \t\n\r\0\x0B.!,;:¿?¡");
    }

    /**
     * Awaitings donde el usuario responde con un número o sí/no: deben tratarse
     * como respuesta del flujo y no como una intención nueva que lo interrumpa.
     */
    private function isProtectedAwaiting(Conversation $conversation): bool
    {
        return in_array($this->conversationManager->getAwaiting($conversation), [
            'appointment_selection',
            'cancel_confirmation',
        ], true);
    }

    private function interruptFlow(Conversation $conversation, string $userMessage, Intent $intent): array
    {
        $awaiting = $this->conversationManager->getAwaiting($conversation);
        $appointmentId = $this->conversationManager->getSlot($conversation, 'appointment_id');

        if ($intent === Intent::CANCEL_APPOINTMENT
            && $awaiting === 'appointment_confirmation'
            && $appointmentId !== null) {
            $cancelled = $this->agendaService->cancelAppointment(
                $conversation->user_identifier,
                $appointmentId,
            );
            $this->conversationManager->finishFlow($conversation);

            return $this->persist(
                $conversation,
                $userMessage,
                $intent,
                $cancelled ? $this->responses->appointmentCancelled() : $this->responses->noAppointmentFound(),
            );
        }

        $this->conversationManager->finishFlow($conversation);

        if ($intent === Intent::REQUEST_APPOINTMENT) {
            return $this->handle($conversation, $userMessage);
        }

        $this->conversationManager->setSlot($conversation, 'intent', $intent->value);

        return $this->startNewExchange($conversation, $userMessage, $intent);
    }

    // ---------------------------------------------------------------------
    // Información general
    // ---------------------------------------------------------------------

    private function askWhichServiceForInfo(Conversation $conversation): string
    {
        $this->conversationManager->setAwaiting($conversation, 'service_info');

        return $this->responses->askWhichService();
    }

    // ---------------------------------------------------------------------
    // Solicitar cita (AgendaService::checkAvailability + requestAppointment)
    // ---------------------------------------------------------------------

    private function startAppointmentRequest(Conversation $conversation): string
    {
        if ($this->usesRealAgenda()) {
            return $this->continueRealAppointmentCollection($conversation);
        }

        $service = $this->conversationManager->getSlot($conversation, 'service');
        $date = $this->conversationManager->getSlot($conversation, 'date');

        if ($service !== null) {
            if ($date !== null) {
                return $this->onAppointmentDateProvided($conversation, $date);
            }

            $this->conversationManager->setAwaiting($conversation, 'appointment_date');

            return $this->responses->askDateForAppointment($service);
        }

        $this->conversationManager->setAwaiting($conversation, 'appointment_service');

        return $this->responses->askServiceForAppointment();
    }

    private function startAvailabilityRequest(Conversation $conversation, string $userMessage): string
    {
        if (preg_match('/\bpara\s+(.+)$/iu', trim($userMessage), $matches) === 1) {
            $service = trim($matches[1], " \t\n\r\0\x0B?.!");
            $this->conversationManager->setSlot($conversation, 'service', $service);
            $this->conversationManager->setAwaiting($conversation, 'availability_date');

            return $this->responses->askDateForAvailability($service);
        }

        $this->conversationManager->setAwaiting($conversation, 'availability_service');

        return $this->responses->askServiceForAvailability();
    }

    private function onAvailabilityServiceProvided(Conversation $conversation, string $userMessage): string
    {
        $service = trim($userMessage);
        $this->conversationManager->setSlot($conversation, 'service', $service);
        $this->conversationManager->setAwaiting($conversation, 'availability_date');

        return $this->responses->askDateForAvailability($service);
    }

    /**
     * Reutiliza el extractor de fechas del proyecto (hoy, mañana, pasado
     * mañana, día de la semana, YYYY-MM-DD, DD/MM/YYYY). Si no hay fecha
     * reconocible se devuelve el texto original para que la validación central
     * produzca el mensaje normal de fecha inválida.
     */
    private function extractDateInput(string $userMessage): string
    {
        return $this->dateExtractor->extract($userMessage) ?? trim($userMessage);
    }

    private function onAvailabilityDateProvided(Conversation $conversation, string $userMessage): string
    {
        $date = $this->extractDateInput($userMessage);

        if (! $this->conversationManager->isValidDate($date)) {
            return $this->responses->invalidDate();
        }

        $service = $this->conversationManager->getSlot($conversation, 'service');
        $slots = $this->agendaService->checkAvailability($service, $date);

        $this->conversationManager->setSlot($conversation, 'date', $date);
        $this->conversationManager->setSlot($conversation, 'available_slots', $slots);
        $this->conversationManager->finishFlow($conversation);

        return empty($slots)
            ? $this->responses->noAvailability($date)
            : $this->responses->availableSlots($date, $slots);
    }

    private function onAppointmentServiceProvided(Conversation $conversation, string $userMessage): string
    {
        $service = trim($userMessage);

        if ($this->usesRealAgenda()) {
            $options = $this->conversationManager->getSlot($conversation, 'service_options', []);

            if (ctype_digit($service) && isset($options[(int) $service - 1])) {
                $match = $options[(int) $service - 1];
                $this->conversationManager->setSlot($conversation, 'service', $match['nombre']);
                $this->conversationManager->setSlot($conversation, 'servicio_id', $match['id']);
                $this->conversationManager->setSlot($conversation, 'service_options', null);

                return $this->continueRealAppointmentCollection($conversation);
            }

            return $this->resolveService($conversation, $service);
        }

        $this->conversationManager->setSlot($conversation, 'service', $service);
        $this->conversationManager->setAwaiting($conversation, 'appointment_date');

        return $this->responses->askDateForAppointment($service);
    }

    private function onAppointmentDateProvided(Conversation $conversation, string $userMessage): string
    {
        $date = $this->extractDateInput($userMessage);

        if (! $this->conversationManager->isValidDate($date)) {
            return $this->responses->invalidDate();
        }

        if ($this->usesRealAgenda()) {
            $this->conversationManager->setSlot($conversation, 'date', $date);

            return $this->continueRealAppointmentCollection($conversation);
        }

        $service = $this->conversationManager->getSlot($conversation, 'service');

        $slots = $this->agendaService->checkAvailability($service, $date);

        if (empty($slots)) {
            // Se mantiene en 'appointment_date' para que el usuario intente otra fecha.
            return $this->responses->noAvailability($date);
        }

        $this->conversationManager->setSlot($conversation, 'date', $date);
        $this->conversationManager->setSlot($conversation, 'available_slots', $slots);
        $this->conversationManager->setAwaiting($conversation, 'appointment_time');

        $selectedTime = $this->conversationManager->getSlot($conversation, 'time');

        if ($selectedTime !== null) {
            return $this->onAppointmentTimeProvided($conversation, $selectedTime);
        }

        return $this->responses->availableSlots($date, $slots);
    }

    private function onAppointmentTimeProvided(Conversation $conversation, string $userMessage): string
    {
        if ($this->usesRealAgenda()) {
            $newDate = $this->dateExtractor->extract($userMessage);

            if ($newDate !== null) {
                if (! $this->conversationManager->isValidDate($newDate)) {
                    return $this->responses->invalidDate();
                }

                $this->conversationManager->setSlot($conversation, 'date', $newDate);
            }

            $time = $this->timeExtractor->extract($userMessage);

            if ($time === null) {
                return $this->responses->askConcreteTime();
            }

            if (! $this->conversationManager->isValidTime($time)) {
                return $this->responses->invalidTime();
            }

            $this->conversationManager->setSlot($conversation, 'time', $time);

            return $this->checkRealAvailability($conversation);
        }

        $time = $this->timeExtractor->extract($userMessage);
        $service = $this->conversationManager->getSlot($conversation, 'service');
        $date = $this->conversationManager->getSlot($conversation, 'date');
        $availableSlots = $this->conversationManager->getSlot($conversation, 'available_slots', []);

        if ($time === null) {
            return $this->responses->availableSlots($date, $availableSlots);
        }

        if (! $this->conversationManager->isValidTime($time)) {
            return $this->responses->invalidTime();
        }

        if (! collect($availableSlots)->pluck('time')->contains($time)) {
            return $this->responses->unavailableTime($time, $availableSlots);
        }

        $this->conversationManager->setSlot($conversation, 'time', $time);

        try {
    $appointment = $this->agendaService->requestAppointment(
        $conversation->user_identifier,
        $service,
        $date,
        $time,
    );
} catch (Throwable $exception) {
    Log::warning('No se pudo registrar la solicitud de cita en la agenda.', [
        'reason' => $exception->getMessage(),
    ]);

    $this->conversationManager->finishFlow($conversation);

    return $this->responses->appointmentRequestFailed();
}

$this->conversationManager->setSlot($conversation, 'appointment_id', $appointment['id']);
$this->conversationManager->setAwaiting($conversation, 'appointment_confirmation');

return $this->responses->askAppointmentConfirmation($appointment);
    }

    private function onAppointmentConfirmationProvided(Conversation $conversation, string $userMessage): string
    {
        $normalized = mb_strtolower(trim($userMessage));
        $appointmentId = $this->conversationManager->getSlot($conversation, 'appointment_id');

        if (preg_match('/^(?:s[ií]|si|confirmar|acepto)$/u', $normalized) === 1) {
            $appointment = $this->agendaService->confirmAppointment(
                $conversation->user_identifier,
                $appointmentId,
            );

            if ($appointment === null) {
                $this->conversationManager->finishFlow($conversation);

                return $this->responses->noAppointmentFound();
            }

            $this->conversationManager->finishFlow($conversation);

            return $this->responses->appointmentConfirmed($appointment);
        }

        if (preg_match('/^(?:no|cancelar|rechazar)$/u', $normalized) === 1) {
            $cancelled = $this->agendaService->cancelAppointment(
                $conversation->user_identifier,
                $appointmentId,
            );

            $this->conversationManager->finishFlow($conversation);

            return $cancelled
                ? $this->responses->appointmentCancelled()
                : $this->responses->noAppointmentFound();
        }

        return $this->responses->appointmentConfirmationPrompt();
    }

    // ---------------------------------------------------------------------
    // Consultar cita (AgendaService::getAppointment)
    // ---------------------------------------------------------------------

    private function checkAppointment(Conversation $conversation): string
    {
        if ($this->usesRealAgenda()) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_query_document');

            return $this->responses->askQueryDocument();
        }

        $appointment = $this->agendaService->getAppointment($conversation->user_identifier);

        return $appointment
            ? $this->responses->appointmentFound($appointment)
            : $this->responses->noAppointmentFound();
    }

    /**
     * Resuelve el paciente por documento y devuelve sus citas (agenda real).
     * @return array{status: string, appointments: array<int, array<string, mixed>>}
     */
    private function appointmentsForDocument(string $document): array
    {
        try {
            $result = $this->patientResolver->resolve($document);
        } catch (ClinicApiException) {
            return ['status' => 'patient_lookup_failed', 'appointments' => []];
        }

        if (in_array($result['status'], ['not_found', 'multiple_matches'], true)) {
            return ['status' => $result['status'], 'appointments' => []];
        }

        try {
            $appointments = $this->agendaService->appointmentsByPatient((int) $result['data']['id']);
        } catch (ClinicApiException) {
            return ['status' => 'appointments_lookup_failed', 'appointments' => []];
        }

        return ['status' => 'ok', 'appointments' => $appointments];
    }

    /**
     * Convierte un estado fallido de la búsqueda (paciente/citas) en su mensaje
     * amigable, cerrando el flujo. Devuelve null si el estado es válido.
     */
    private function lookupFailureReply(Conversation $conversation, string $status): ?string
    {
        $reply = match ($status) {
            'patient_lookup_failed' => $this->responses->patientLookupFailed(),
            'not_found' => $this->responses->queryPatientNotFound(),
            'multiple_matches' => $this->responses->patientAmbiguous(),
            'appointments_lookup_failed' => $this->responses->patientAppointmentsLookupFailed(),
            default => null,
        };

        if ($reply !== null) {
            $this->conversationManager->finishFlow($conversation);
        }

        return $reply;
    }

    /**
     * Consulta de citas en la agenda real: DNI -> paciente -> citas del paciente.
     * El documento nunca se interpreta como cita_id.
     */
    private function onQueryDocumentProvided(Conversation $conversation, string $userMessage): string
    {
        $document = trim($userMessage);

        if ($document === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\-]{3,29}\z/u', $document) !== 1) {
            return $this->responses->askQueryDocument();
        }

        $lookup = $this->appointmentsForDocument($document);

        if ($reply = $this->lookupFailureReply($conversation, $lookup['status'])) {
            return $reply;
        }

        $this->conversationManager->finishFlow($conversation);

        if ($lookup['appointments'] === []) {
            return $this->responses->noAppointmentFound();
        }

        return $this->responses->appointmentsFound($lookup['appointments']);
    }

    /**
     * Cancelar cita (agenda real): DNI -> paciente -> cita(s) -> confirmación.
     * Con una sola cita se confirma directamente; con varias se pide elegir una.
     */
    private function onCancelDocumentProvided(Conversation $conversation, string $userMessage): string
    {
        $document = trim($userMessage);

        if ($document === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\-]{3,29}\z/u', $document) !== 1) {
            return $this->responses->askCancelDocument();
        }

        $lookup = $this->appointmentsForDocument($document);

        if ($reply = $this->lookupFailureReply($conversation, $lookup['status'])) {
            return $reply;
        }

        $appointments = $lookup['appointments'];

        if ($appointments === []) {
            $this->conversationManager->finishFlow($conversation);

            return $this->responses->noAppointmentFound();
        }

        if (count($appointments) === 1) {
            return $this->startCancelConfirmation($conversation, $appointments[0]);
        }

        $this->conversationManager->setSlot($conversation, 'appointment_options', $appointments);
        $this->conversationManager->setAwaiting($conversation, 'appointment_selection');

        return $this->responses->askAppointmentSelection($appointments, 'cancelar');
    }

    private function startCancelConfirmation(Conversation $conversation, array $appointment): string
    {
        $this->conversationManager->setSlot($conversation, 'appointment_id', $appointment['id']);
        $this->conversationManager->setSlot($conversation, 'appointment', $appointment);
        $this->conversationManager->setAwaiting($conversation, 'cancel_confirmation');

        return $this->responses->askCancelConfirmation($appointment);
    }

    /**
     * Reprogramar cita (agenda real): DNI -> paciente -> cita(s) -> nueva fecha/hora.
     */
    private function onRescheduleDocumentProvided(Conversation $conversation, string $userMessage): string
    {
        $document = trim($userMessage);

        if ($document === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\-]{3,29}\z/u', $document) !== 1) {
            return $this->responses->askRescheduleDocument();
        }

        $lookup = $this->appointmentsForDocument($document);

        if ($reply = $this->lookupFailureReply($conversation, $lookup['status'])) {
            return $reply;
        }

        $appointments = $lookup['appointments'];

        if ($appointments === []) {
            $this->conversationManager->finishFlow($conversation);

            return $this->responses->noAppointmentToReschedule();
        }

        if (count($appointments) === 1) {
            return $this->startRescheduleOf($conversation, $appointments[0]);
        }

        $this->conversationManager->setSlot($conversation, 'appointment_options', $appointments);
        $this->conversationManager->setAwaiting($conversation, 'appointment_selection');

        return $this->responses->askAppointmentSelection($appointments, 'reprogramar');
    }

    private function startRescheduleOf(Conversation $conversation, array $appointment): string
    {
        $this->conversationManager->setSlot($conversation, 'appointment_id', $appointment['id']);
        $this->conversationManager->setSlot($conversation, 'service', (string) $appointment['service']);
        $this->conversationManager->setAwaiting($conversation, 'reschedule_date');

        return $this->responses->askDateForAppointment((string) $appointment['service']);
    }

    /**
     * Selección de una cita de la lista mostrada (cancelar o reprogramar).
     */
    private function onAppointmentSelected(Conversation $conversation, string $userMessage): string
    {
        $options = $this->conversationManager->getSlot($conversation, 'appointment_options', []);
        $choice = trim($userMessage);
        $index = ctype_digit($choice) ? (int) $choice - 1 : -1;

        if ($index < 0 || ! isset($options[$index])) {
            $storedIntent = $this->conversationManager->getSlot($conversation, 'intent');

            return $this->responses->askAppointmentSelection(
                $options,
                $storedIntent === 'cancel_appointment' ? 'cancelar' : 'reprogramar',
            );
        }

        $selected = $options[$index];
        $this->conversationManager->setSlot($conversation, 'appointment_options', null);

        $storedIntent = $this->conversationManager->getSlot($conversation, 'intent');

        if ($storedIntent === 'cancel_appointment') {
            return $this->startCancelConfirmation($conversation, $selected);
        }

        return $this->startRescheduleOf($conversation, $selected);
    }

    /**
     * Confirmación explícita (sí/no) antes de cancelar una cita real.
     */
    private function onCancelConfirmationProvided(Conversation $conversation, string $userMessage): string
    {
        $answer = mb_strtolower(trim($userMessage));
        $appointmentId = $this->conversationManager->getSlot($conversation, 'appointment_id');

        if (preg_match('/^(?:s[ií]|si|confirmar|acepto)$/u', $answer) === 1) {
            try {
                $cancelled = $this->agendaService->cancelAppointment($conversation->user_identifier, $appointmentId);
            } catch (ClinicApiException) {
                $this->conversationManager->finishFlow($conversation);

                return $this->responses->appointmentCancellationFailed();
            }

            $this->conversationManager->finishFlow($conversation);

            return $cancelled
                ? $this->responses->appointmentCancelled()
                : $this->responses->noAppointmentFound();
        }

        if (preg_match('/^(?:no|rechazar)$/u', $answer) === 1) {
            $this->conversationManager->finishFlow($conversation);

            return $this->responses->appointmentCancellationKept();
        }

        return $this->responses->cancelConfirmationPrompt();
    }

    // ---------------------------------------------------------------------
    // Cancelar cita (AgendaService::getAppointment + cancelAppointment)
    // ---------------------------------------------------------------------

    private function cancelAppointment(Conversation $conversation): string
{
    if ($this->usesRealAgenda()) {
        $this->conversationManager->setAwaiting($conversation, 'cancel_query_document');

        return $this->responses->askCancelDocument();
    }

    try {
        $appointment = $this->agendaService->getAppointment($conversation->user_identifier);

        if (! $appointment) {
            return $this->responses->noAppointmentFound();
        }

        $cancelled = $this->agendaService->cancelAppointment(
            $conversation->user_identifier,
            $appointment['id']
        );
    } catch (ClinicApiException) {
        return $this->responses->patientAppointmentsLookupFailed();
    }

    return $cancelled
        ? $this->responses->appointmentCancelled()
        : $this->responses->noAppointmentFound();
}

    // ---------------------------------------------------------------------
    // Modificar cita (AgendaService::getAppointment + rescheduleAppointment)
    // ---------------------------------------------------------------------

    private function startReschedule(Conversation $conversation): string
    {
        if ($this->usesRealAgenda()) {
            $this->conversationManager->setAwaiting($conversation, 'reschedule_query_document');

            return $this->responses->askRescheduleDocument();
        }

        try {
            $appointment = $this->agendaService->getAppointment($conversation->user_identifier);
        } catch (ClinicApiException) {
            return $this->responses->patientAppointmentsLookupFailed();
        }

        if (! $appointment) {
            return $this->responses->noAppointmentToReschedule();
        }

        $this->conversationManager->setSlot($conversation, 'appointment_id', $appointment['id']);
        $this->conversationManager->setSlot($conversation, 'service', $appointment['service']);
        $this->conversationManager->setAwaiting($conversation, 'reschedule_date');

        return $this->responses->askDateForAppointment($appointment['service']);
    }

    private function onRescheduleDateProvided(Conversation $conversation, string $userMessage): string
    {
        $date = $this->extractDateInput($userMessage);

        if (! $this->conversationManager->isValidDate($date)) {
            return $this->responses->invalidDate();
        }

        $this->conversationManager->setSlot($conversation, 'date', $date);

        if ($this->usesRealAgenda()) {
            // La validación del hueco la hace CABANILLAS al reprogramar (409 ante conflicto).
            $this->conversationManager->setAwaiting($conversation, 'reschedule_time');

            return '¿A qué hora prefieres? (ej: 10:30)';
        }

        try {
            $slots = $this->agendaService->checkAvailability(
                (string) $this->conversationManager->getSlot($conversation, 'service'),
                $date,
            );
        } catch (ClinicApiException) {
            $this->conversationManager->setAwaiting($conversation, 'reschedule_date');

            return $this->responses->availabilityLookupFailed();
        }

        $this->conversationManager->setSlot($conversation, 'available_slots', $slots);
        $this->conversationManager->setAwaiting($conversation, 'reschedule_time');

        return '¿A qué hora prefieres? (ej: 10:30)';
    }

    private function onRescheduleTimeProvided(
    Conversation $conversation,
    string $userMessage
): string {
    $appointmentId = $this->conversationManager->getSlot($conversation, 'appointment_id');
    $date = $this->conversationManager->getSlot($conversation, 'date');
    $time = $this->timeExtractor->extract($userMessage);
    $availableSlots = $this->conversationManager->getSlot($conversation, 'available_slots', []);

    if ($time === null) {
        if ($this->usesRealAgenda()) {
            return $this->responses->askConcreteTime();
        }

        return $this->responses->availableSlots($date, $availableSlots);
    }

    if (! $this->conversationManager->isValidTime($time)) {
        return $this->responses->invalidTime();
    }

    if ($this->usesRealAgenda()) {
        try {
            $appointment = $this->agendaService->rescheduleAppointment(
                $conversation->user_identifier,
                $appointmentId,
                (string) $date,
                $time,
            );
        } catch (ClinicApiException $exception) {
            if ($exception->status === 409) {
                $this->conversationManager->setAwaiting($conversation, 'reschedule_time');

                return $this->responses->appointmentConflict();
            }

            $this->conversationManager->finishFlow($conversation);

            return $this->responses->appointmentRescheduleFailed();
        }

        $this->conversationManager->finishFlow($conversation);

        if ($appointment === null) {
            return $this->responses->noAppointmentFound();
        }

        return $this->responses->appointmentRescheduled($appointment);
    }

    if (! collect($availableSlots)->pluck('time')->contains($time)) {
        return $this->responses->unavailableTime($time, $availableSlots);
    }

    $appointment = $this->agendaService->rescheduleAppointment(
    $conversation->user_identifier,
    $appointmentId,
    $date,
    $time,
);

if ($appointment === null) {
    return $this->responses->noAppointmentFound();
}

$this->conversationManager->finishFlow($conversation);

return $this->responses->appointmentRescheduled($appointment);
}

    // ---------------------------------------------------------------------
    // Escalamiento a humano / cierre
    // ---------------------------------------------------------------------

    private function escalateToHuman(Conversation $conversation, string $userMessage, Intent $intent): array
    {
        $this->conversationManager->escalateToHuman($conversation);
        $reply = $this->responses->humanHandoff();

        return $this->persist($conversation, $userMessage, $intent, $reply);
    }

    private function respondWhileInHandoff(Conversation $conversation, string $userMessage): array
    {
        // Mientras está en HANDOFF_TO_HUMAN el bot no interpreta intenciones:
        // solo registra el mensaje para que el humano lo vea, y avisa que ya
        // está siendo atendido. (El panel/handoff real lo construirá quien
        // desarrolle esa parte; aquí solo dejamos el dato persistido).
        $reply = 'Ya estás en contacto con un miembro de nuestro equipo, en breve te responderá.';

        return $this->persist($conversation, $userMessage, Intent::HUMAN_HANDOFF, $reply);
    }

    /**
     * Handoff BOT -> HUMANO silencioso.
     *
     * Cuando la conversación está ASSIGNED o ATTENDING, un agente humano tiene
     * el control: el bot NO genera respuesta automática. Solo se persiste el
     * mensaje del cliente (quedando como no leído) para que el agente lo
     * responda desde el dashboard.
     *
     * @return array{conversation: Conversation, message: string, intent: string, handled_by: string}
     */
    private function recordCustomerMessageWhileAgentControls(Conversation $conversation, string $userMessage): array
    {
        $conversation->last_message_at = now();
        $conversation->save();

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => $userMessage,
            'intent' => Intent::HUMAN_HANDOFF->value,
        ]);

        return [
            'conversation' => $conversation,
            'message' => '',
            'intent' => Intent::HUMAN_HANDOFF->value,
            'handled_by' => 'agent',
        ];
    }


    private function closeConversation(Conversation $conversation): string
    {
        $this->conversationManager->close($conversation);

        return $this->responses->farewell();
    }

    private function unknownResponse(Conversation $conversation, string $userMessage): string
    {
        try {
            $history = $conversation->messages()
                ->latest('id')
                ->limit(10)
                ->get(['sender', 'message'])
                ->reverse()
                ->map(fn (Message $message): array => [
                    'sender' => $message->sender,
                    'message' => $message->message,
                ])
                ->values()
                ->all();

            return $this->aiService->generateReply($userMessage, $history);
        } catch (Throwable $exception) {
            Log::warning('Gemini no disponible; se usa respuesta predefinida.', [
                'reason' => $exception->getMessage(),
            ]);

            return $this->responses->unknown();
        }
    }

    // ---------------------------------------------------------------------
    // Persistencia
    // ---------------------------------------------------------------------

    /**
     * @return array{conversation: Conversation, message: string, intent: string}
     */
    private function persist(Conversation $conversation, string $userMessage, Intent $intent, string $reply): array
    {
        // Campo aditivo del sistema de agentes (orden y actividad reciente de
        // la bandeja). No modifica el comportamiento conversacional del bot.
        $conversation->last_message_at = now();

        $conversation->save();

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => $userMessage,
            'intent' => $intent->value,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_BOT,
            'message' => $reply,
            'intent' => null,
        ]);

        // Sistema de agentes: si el bot atendió el mensaje, los mensajes del
        // cliente quedan marcados como leídos. Los no leídos del dashboard son
        // únicamente los que esperan/necesitan a un agente humano.
        if ($conversation->state?->isBotControlled() === true) {
            $conversation->messages()
                ->where('sender', Message::SENDER_USER)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }


        return [
            'conversation' => $conversation,
            'message' => $reply,
            'intent' => $intent->value,
        ];
    }

    private function usesRealAgenda(): bool
    {
        return $this->agendaService instanceof ApiAgendaService;
    }

    private function continueRealAppointmentCollection(Conversation $conversation): string
    {
        if ($this->conversationManager->getSlot($conversation, 'paciente_id') === null) {
            $document = $this->conversationManager->getSlot($conversation, 'patient_document');

            if ($document === null) {
                $this->conversationManager->setAwaiting($conversation, 'patient_document');

                return $this->responses->askPatientDocument();
            }

            return $this->resolvePatient($conversation, $document);
        }

        if ($this->conversationManager->getSlot($conversation, 'servicio_id') === null) {
            $service = $this->conversationManager->getSlot($conversation, 'service');

            if ($service === null) {
                $this->conversationManager->setAwaiting($conversation, 'appointment_service');

                return $this->responses->askServiceForAppointment();
            }

            return $this->resolveService($conversation, $service);
        }

        if ($this->conversationManager->getSlot($conversation, 'profesional_id') === null) {
            $professional = $this->conversationManager->getSlot($conversation, 'professional');

            if ($professional === null) {
                $this->conversationManager->setAwaiting($conversation, 'appointment_professional');

                return $this->responses->askProfessional();
            }

            return $this->resolveProfessional($conversation, $professional);
        }

        if ($this->conversationManager->getSlot($conversation, 'date') === null) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_date');

            return $this->responses->askDateForAppointment((string) $this->conversationManager->getSlot($conversation, 'service'));
        }

        if ($this->conversationManager->getSlot($conversation, 'time') === null) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_time');

            return '¿A qué hora prefieres? (ej: 16:00)';
        }

        return $this->checkRealAvailability($conversation);
    }

    private function checkRealAvailability(Conversation $conversation): string
    {
        $patientId = $this->conversationManager->getSlot($conversation, 'paciente_id');
        $serviceId = $this->conversationManager->getSlot($conversation, 'servicio_id');
        $professionalId = $this->conversationManager->getSlot($conversation, 'profesional_id');
        $date = $this->conversationManager->getSlot($conversation, 'date');
        $time = $this->conversationManager->getSlot($conversation, 'time');

        if ($patientId === null || $serviceId === null || $professionalId === null || $date === null || $time === null) {
            return $this->continueRealAppointmentCollection($conversation);
        }

        if (! is_numeric($serviceId) || (int) $serviceId < 1 || ! is_numeric($professionalId) || (int) $professionalId < 1) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_professional');

            return $this->responses->availabilityLookupFailed();
        }

        if (! is_string($date) || ! $this->conversationManager->isValidDate($date)) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_date');

            return $this->responses->invalidDate();
        }

        if (! is_string($time) || ! $this->conversationManager->isValidTime($time)) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_time');

            return $this->responses->askConcreteTime();
        }

        $existingAppointmentId = $this->conversationManager->getSlot($conversation, 'appointment_id');

        if (is_numeric($existingAppointmentId) && (int) $existingAppointmentId > 0) {
            $existingAppointment = $this->conversationManager->getSlot($conversation, 'appointment');

            if (! is_array($existingAppointment)) {
                $existingAppointment = [
                    'id' => (int) $existingAppointmentId,
                    'service' => (string) $this->conversationManager->getSlot($conversation, 'service'),
                    'date' => $date,
                    'time' => $time,
                ];
            }
            $this->conversationManager->clearAwaiting($conversation);

            return $this->responses->appointmentAlreadyCreated($existingAppointment);
        }

        $consultorioId = $this->conversationManager->getSlot($conversation, 'consultorio_id');
        $consultorioId = is_numeric($consultorioId) && (int) $consultorioId > 0 ? (int) $consultorioId : null;

        try {
            $available = $this->realAgenda()->checkSpecificAvailability(
                (int) $serviceId,
                (int) $professionalId,
                $date,
                $time,
                $consultorioId,
            );
        } catch (ClinicApiException) {
            $this->conversationManager->setSlot($conversation, 'availability_checked', false);
            $this->conversationManager->setAwaiting($conversation, 'appointment_time');

            return $this->responses->availabilityLookupFailed();
        }

        $this->conversationManager->setSlot($conversation, 'availability_checked', true);
        $this->conversationManager->setSlot($conversation, 'availability_result', $available);

        if (! $available) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_time');

            return $this->responses->availabilityUnavailable($date, $time);
        }

        try {
            $appointment = $this->realAgenda()->requestAppointmentByIds(
                (int) $patientId,
                (int) $professionalId,
                (int) $serviceId,
                $date,
                $time,
                $consultorioId,
            );
        } catch (ClinicApiException $exception) {
            if ($exception->status === 409) {
                $this->conversationManager->setSlot($conversation, 'appointment_creation_status', 'failed');
                $this->conversationManager->setAwaiting($conversation, 'appointment_time');

                return $this->responses->appointmentConflict();
            }

            if ($exception->status === null) {
                $this->conversationManager->setSlot($conversation, 'appointment_creation_status', 'unknown');
                $this->conversationManager->clearAwaiting($conversation);

                return $this->responses->appointmentCreationUncertain();
            }

            $this->conversationManager->setSlot($conversation, 'appointment_creation_status', 'failed');
            $this->conversationManager->setAwaiting($conversation, 'appointment_time');

            return $this->responses->appointmentCreationFailed();
        }

        $this->conversationManager->setSlot($conversation, 'appointment_id', $appointment['id']);
        $this->conversationManager->setSlot($conversation, 'appointment', $appointment);
        $this->conversationManager->setSlot($conversation, 'appointment_creation_status', 'created');
        $this->conversationManager->clearAwaiting($conversation);

        return $this->responses->appointmentCreated($appointment);
    }

    private function realAgenda(): ApiAgendaService
    {
        return $this->agendaService;
    }

    private function onPatientDocumentProvided(Conversation $conversation, string $userMessage): string
    {
        $document = trim($userMessage);

        if ($document === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\-]{3,29}\z/u', $document) !== 1) {
            return $this->responses->askPatientDocument();
        }

        $this->conversationManager->setSlot($conversation, 'patient_document', $document);

        return $this->resolvePatient($conversation, $document);
    }

    private function resolvePatient(Conversation $conversation, string $document): string
    {
        try {
            $result = $this->patientResolver->resolve($document);
        } catch (ClinicApiException) {
            return $this->responses->patientLookupFailed();
        }

        if ($result['status'] === 'not_found') {
            // El registro de pacientes no existe en la API actual: se abandona
            // el flujo para no dejar la conversación atrapada pidiendo el DNI.
            $this->conversationManager->finishFlow($conversation);

            return $this->responses->patientNotFound();
        }

        if ($result['status'] === 'multiple_matches') {
            $this->conversationManager->finishFlow($conversation);

            return $this->responses->patientAmbiguous();
        }

        $this->conversationManager->setSlot($conversation, 'paciente_id', $result['data']['id']);

        return $this->continueRealAppointmentCollection($conversation);
    }

    private function resolveService(Conversation $conversation, string $service): string
    {
        try {
            $result = $this->serviceResolver->resolve($service);
        } catch (ClinicApiException) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_service');

            return $this->responses->serviceLookupFailed();
        }

        if ($result['status'] === 'not_found') {
            $this->conversationManager->setAwaiting($conversation, 'appointment_service');

            return $this->responses->serviceNotFound();
        }

        if ($result['status'] === 'multiple_matches') {
            $this->conversationManager->setSlot($conversation, 'service_options', $result['data']);
            $this->conversationManager->setAwaiting($conversation, 'appointment_service');

            return $this->responses->serviceAmbiguous($result['data']);
        }

        $this->conversationManager->setSlot($conversation, 'service', $result['data']['nombre']);
        $this->conversationManager->setSlot($conversation, 'servicio_id', $result['data']['id']);
        $this->conversationManager->setSlot($conversation, 'service_options', null);

        return $this->continueRealAppointmentCollection($conversation);
    }

    private function onAppointmentProfessionalProvided(Conversation $conversation, string $userMessage): string
    {
        $professional = trim($userMessage);
        $options = $this->conversationManager->getSlot($conversation, 'professional_options', []);

        if (ctype_digit($professional) && isset($options[(int) $professional - 1])) {
            $match = $options[(int) $professional - 1];
            $this->conversationManager->setSlot($conversation, 'professional', trim($match['nombres'].' '.$match['apellidos']));
            $this->conversationManager->setSlot($conversation, 'profesional_id', $match['id']);
            $this->conversationManager->setSlot($conversation, 'professional_options', null);

            return $this->continueRealAppointmentCollection($conversation);
        }

        return $this->resolveProfessional($conversation, $professional);
    }

    private function resolveProfessional(Conversation $conversation, string $professional): string
    {
        try {
            $result = $this->professionalResolver->resolve($professional);
        } catch (ClinicApiException) {
            $this->conversationManager->setAwaiting($conversation, 'appointment_professional');

            return $this->responses->professionalLookupFailed();
        }

        if ($result['status'] === 'not_found') {
            $this->conversationManager->setAwaiting($conversation, 'appointment_professional');

            return $this->responses->professionalNotFound();
        }

        if ($result['status'] === 'multiple_matches') {
            $this->conversationManager->setSlot($conversation, 'professional_options', $result['data']);
            $this->conversationManager->setAwaiting($conversation, 'appointment_professional');

            return $this->responses->professionalAmbiguous($result['data']);
        }

        $this->conversationManager->setSlot($conversation, 'professional', trim($result['data']['nombres'].' '.$result['data']['apellidos']));
        $this->conversationManager->setSlot($conversation, 'profesional_id', $result['data']['id']);
        $this->conversationManager->setSlot($conversation, 'professional_options', null);

        return $this->continueRealAppointmentCollection($conversation);
    }

    private function extractDocument(string $message): ?string
    {
        if (preg_match('/\b(?:documento|dni|identificaci[oó]n)\D*([A-Za-z0-9][A-Za-z0-9.\-]{3,29})\b/iu', $message, $matches) === 1) {
            return $matches[1];
        }

        preg_match('/(?<!\d)(\d{6,15})(?!\d)/u', $message, $matches);

        return isset($matches[1]) && preg_match('/\d/u', $matches[1]) === 1 ? $matches[1] : null;
    }
}
