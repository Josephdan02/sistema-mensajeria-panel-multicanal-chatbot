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

        if ($awaitingInput && $this->isFlowInterruption($intent)) {
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
            default => $this->responses->unknown(),
        };

        if ($awaiting === 'service_info') {
            $this->conversationManager->finishFlow($conversation);
        }

        return $this->persist($conversation, $userMessage, $effectiveIntent, $reply);
    }

    private function isFlowInterruption(Intent $intent): bool
    {
        return in_array($intent, [
            Intent::CHECK_APPOINTMENT,
            Intent::CANCEL_APPOINTMENT,
            Intent::RESCHEDULE_APPOINTMENT,
            Intent::REQUEST_APPOINTMENT,
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

    private function onAvailabilityDateProvided(Conversation $conversation, string $userMessage): string
    {
        $date = trim($userMessage);

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
        $date = trim($userMessage);

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
        $appointment = $this->agendaService->getAppointment($conversation->user_identifier);

        return $appointment
            ? $this->responses->appointmentFound($appointment)
            : $this->responses->noAppointmentFound();
    }

    // ---------------------------------------------------------------------
    // Cancelar cita (AgendaService::getAppointment + cancelAppointment)
    // ---------------------------------------------------------------------

    private function cancelAppointment(Conversation $conversation): string
{
    $appointment = $this->agendaService->getAppointment($conversation->user_identifier);

    if (! $appointment) {
        return $this->responses->noAppointmentFound();
    }

    $cancelled = $this->agendaService->cancelAppointment(
        $conversation->user_identifier,
        $appointment['id']
    );

    return $cancelled
        ? $this->responses->appointmentCancelled()
        : $this->responses->noAppointmentFound();
}

    // ---------------------------------------------------------------------
    // Modificar cita (AgendaService::getAppointment + rescheduleAppointment)
    // ---------------------------------------------------------------------

    private function startReschedule(Conversation $conversation): string
    {
        $appointment = $this->agendaService->getAppointment($conversation->user_identifier);

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
        $date = trim($userMessage);

        if (! $this->conversationManager->isValidDate($date)) {
            return $this->responses->invalidDate();
        }

        $this->conversationManager->setSlot($conversation, 'date', $date);
        $slots = $this->agendaService->checkAvailability(
            (string) $this->conversationManager->getSlot($conversation, 'service'),
            $date,
        );
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
        return $this->responses->availableSlots($date, $availableSlots);
    }

    if (! $this->conversationManager->isValidTime($time)) {
        return $this->responses->invalidTime();
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
            return $this->responses->patientNotFound();
        }

        if ($result['status'] === 'multiple_matches') {
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
