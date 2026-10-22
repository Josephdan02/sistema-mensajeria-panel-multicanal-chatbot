<?php

namespace App\Services\Response;

use App\Enums\Intent;
use Illuminate\Support\Str;

class ResponseGenerator
{
    public function askPatientDocument(): string { return 'Claro. Para continuar, necesito tu número de documento.'; }
    public function patientNotFound(): string { return 'No encontré un paciente registrado con ese documento. El registro de pacientes no está disponible mediante este asistente. Puedes escribir "hablar con una persona" para continuar con atención humana o "menú" para volver al inicio.'; }
    public function patientRegistrationUnavailable(): string { return 'El registro de pacientes no está disponible mediante este asistente. Para registrarte puedes escribir "hablar con una persona" y continuar con atención humana, o "menú" para volver al inicio.'; }
    public function flowCancelled(): string { return 'De acuerdo, cancelé el proceso que teníamos en curso. ¿Necesitas algo más? Escribe "menú" para ver las opciones disponibles.'; }
    public function patientAmbiguous(): string { return 'Encontré más de un paciente con ese documento. Necesitamos verificar tus datos antes de continuar.'; }
    public function patientLookupFailed(): string { return 'No pude verificar tus datos en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function askQueryDocument(): string { return 'Claro. Para consultar tus citas necesito tu número de DNI.'; }
    public function queryPatientNotFound(): string { return 'No encontré un paciente registrado con ese documento. Verifica el número e inténtalo de nuevo.'; }
    public function patientAppointmentsLookupFailed(): string { return 'No pude verificar tus citas en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function askCancelDocument(): string { return 'Claro. Para cancelar tu cita necesito tu número de DNI.'; }
    public function askRescheduleDocument(): string { return 'Claro. Para reprogramar tu cita necesito tu número de DNI.'; }
    public function askAppointmentSelection(array $appointments, string $action): string
    {
        $lines = collect($appointments)
            ->map(fn (array $appointment, int $index): string => ($index + 1).". {$appointment['service']} el {$appointment['date']} a las {$appointment['time']} (estado: {$appointment['status']})")
            ->implode("\n");
        $count = count($appointments);

        return "Encontré {$count} ".($count === 1 ? 'cita' : 'citas')." a tu nombre. ¿Cuál deseas {$action}? Escribe el número:\n{$lines}";
    }
    public function askCancelConfirmation(array $appointment): string
    {
        return "¿Confirmas que deseas cancelar tu cita de \"{$appointment['service']}\" del {$appointment['date']} a las {$appointment['time']}? (sí/no)";
    }
    public function cancelConfirmationPrompt(): string { return 'Responde sí para cancelar la cita o no para mantenerla.'; }
    public function appointmentCancellationKept(): string { return 'De acuerdo, tu cita se mantiene sin cambios. ¿Necesitas algo más?'; }
    public function appointmentCancellationFailed(): string { return 'No pude cancelar la cita en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function appointmentRescheduleFailed(): string { return 'No pude reprogramar la cita en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function appointmentOperationUncertain(string $operation): string
    {
        return "No pude confirmar si la cita fue {$operation}. No vuelvas a intentarlo todavía; un miembro del equipo debe verificarlo.";
    }
    public function appointmentsFound(array $appointments): string
    {
        $lines = collect($appointments)
            ->map(fn (array $appointment): string => "- {$appointment['service']} el {$appointment['date']} a las {$appointment['time']} (estado: {$appointment['status']})")
            ->implode("\n");
        $count = count($appointments);

        return "Encontré {$count} ".($count === 1 ? 'cita' : 'citas')." a tu nombre:\n{$lines}";
    }
    public function serviceLookupFailed(): string { return 'No pude verificar los servicios en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function professionalLookupFailed(): string { return 'No pude verificar los profesionales en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function askProfessional(): string { return '¿Con qué profesional deseas atenderte?'; }
    public function serviceNotFound(): string { return 'No encontré ese servicio. Indícame un servicio válido, por favor.'; }
    public function serviceAmbiguous(array $services): string { return 'Encontré varios servicios que coinciden: '.collect($services)->pluck('nombre')->join(', ').'. ¿Cuál deseas?'; }
    public function professionalNotFound(): string { return 'No encontré ese profesional. Indícame otro nombre, por favor.'; }
    public function professionalAmbiguous(array $professionals): string { return 'Encontré varios profesionales que coinciden: '.collect($professionals)->map(fn (array $item): string => trim($item['nombres'].' '.$item['apellidos']))->join(', ').'. ¿Cuál prefieres?'; }
    public function appointmentDetailsCollected(): string { return 'Ya tengo tus datos, el servicio, el profesional, la fecha y la hora. La disponibilidad y creación de la cita se realizarán en el siguiente paso.'; }
    public function availabilityAvailable(string $date, string $time, string $professional): string { return "Sí, hay disponibilidad el {$date} a las {$time} con {$professional}. La cita todavía no ha sido registrada."; }
    public function availabilityUnavailable(string $date, string $time): string { return "Lo siento, la hora {$time} del {$date} no está disponible. ¿Qué otra hora te gustaría intentar?"; }
    public function availabilityLookupFailed(): string { return 'No puedo verificar la disponibilidad en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function askConcreteTime(): string { return '¿Qué hora prefieres? Por favor, indícame una hora concreta, por ejemplo 16:00.'; }
    public function appointmentCreated(array $appointment): string { return "Tu cita para \"{$appointment['service']}\" con el profesional indicado el {$appointment['date']} a las {$appointment['time']} fue registrada correctamente. Número de cita: {$appointment['id']}."; }
    public function appointmentAlreadyCreated(array $appointment): string { return "La cita para \"{$appointment['service']}\" el {$appointment['date']} a las {$appointment['time']} ya fue registrada. Número de cita: {$appointment['id']}."; }
    public function appointmentConflict(): string { return 'Esa hora dejó de estar disponible antes de registrarse. ¿Qué otra hora deseas intentar?'; }
    public function appointmentCreationFailed(): string { return 'No pude registrar la cita en este momento. Por favor, inténtalo nuevamente más tarde.'; }
    public function appointmentCreationUncertain(): string { return 'No pude confirmar el resultado del registro de la cita. Por favor, no vuelvas a intentarlo todavía; necesitamos verificar su estado.'; }
    public function appointmentCreationInvalid(): string { return 'La API clínica no devolvió los datos necesarios para confirmar el registro de la cita.'; }
    public function greeting(): string
    {
        return "Hola 👋 Bienvenido/a a la Clínica Dental Cabanillas. Soy el asistente virtual y puedo ayudarte con:\n\n".
            "1. Consultar tus citas\n".
            "2. Agendar una cita\n".
            "3. Reprogramar una cita\n".
            "4. Cancelar una cita\n".
            "5. Consultar servicios y horarios\n".
            "6. Consultar disponibilidad\n\n".
            '¿Qué deseas hacer? Puedes escribir un número o decirlo con tus palabras.';
    }
    public function farewell(): string { return '¡Gracias por escribirnos! Que tengas un excelente día. 😊'; }
    public function generalInfo(): string { return 'Somos una clínica dental enfocada en brindarte la mejor atención. ¿Sobre qué te gustaría saber más: servicios, precios, horarios o ubicación?'; }
    public function askWhichService(): string { return 'Claro. ¿Sobre qué servicio deseas información? (por ejemplo: limpieza dental, ortodoncia, blanqueamiento, extracciones)'; }
    /**
     * Respuesta informativa del flujo service_info.
     *
     * $service es el nombre canónico resuelto con el catálogo local
     * (config/chatbot.services) mediante el extractor existente, o null cuando
     * el mensaje no identifica un tratamiento (desconocido o ambiguo). Solo se
     * muestran descripciones y beneficios que existan en el catálogo: nunca se
     * inventan precios, duraciones, horarios ni disponibilidad.
     */
    public function serviceInfo(?string $service, string $originalMessage = ''): string
    {
        $asksUnverifiedData = $this->asksUnverifiedData($originalMessage);

        if ($service === null) {
            return $asksUnverifiedData
                ? $this->unverifiedTreatmentData()
                : $this->serviceNotFound();
        }

        $entry = $this->catalogEntryByName($service);
        $description = is_array($entry) ? ($entry['description'] ?? null) : null;
        $benefits = is_array($entry) ? ($entry['benefits'] ?? null) : null;

        if (is_string($description) && trim($description) !== '') {
            $response = "Sobre \"{$service}\": {$description}";
        } else {
            $response = "Sobre \"{$service}\": con gusto te damos más detalles.";
        }

        if (is_array($benefits) && $benefits !== []) {
            $response .= ' Beneficios: '.implode(', ', $benefits).'.';
        }

        $response .= $asksUnverifiedData
            ? ' '.$this->unverifiedTreatmentData().' ¿Quieres que te ayude a agendar una cita para este servicio?'
            : ' Un miembro del equipo puede confirmarte precio exacto y disponibilidad. ¿Quieres que te ayude a agendar una cita para este servicio?';

        return $response;
    }

    /** @return array{name: string, aliases: array<int, string>, description?: string, benefits?: array<int, string>}|null */
    private function catalogEntryByName(string $service): ?array
    {
        foreach (config('chatbot.services', []) as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $service) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Detección de consultas sobre datos que el catálogo NO verifica
     * (precios, horarios, disponibilidad de citas y duración). Solo da forma
     * a la respuesta dentro del flujo service_info: no altera la detección de
     * intenciones ni las interrupciones/derivaciones existentes.
     */
    private function asksUnverifiedData(string $message): bool
    {
        $normalized = Str::ascii(Str::lower(Str::squish($message)));

        return preg_match('/\b(precios?|cuesta|cuanto\s+cuesta\w*|cuanto\s+sale|costos?|tarifas?)\b/', $normalized) === 1
            || preg_match('/\b(horarios?|abren|cierran|atienden)\b/', $normalized) === 1
            || preg_match('/\b(disponibilidad|agenda)\b/', $normalized) === 1
            || preg_match('/\b(duracion|dura|tarda\w*|cuanto\s+tarda\w*|cuanto\s+demora\w*)\b/', $normalized) === 1;
    }

    private function unverifiedTreatmentData(): string
    {
        return 'Precios, horarios, disponibilidad de citas y duración deben confirmarse con la clínica o con un agente.';
    }
    public function services(): string { return 'Nuestros servicios principales son: limpieza dental, ortodoncia, blanqueamiento, extracciones, endodoncia e implantes. ¿Sobre cuál deseas más información?'; }
    public function pricing(): string { return 'Nuestros servicios tienen diferentes precios según el tratamiento. ¿Qué tratamiento deseas consultar?'; }
    public function schedule(): string { return 'Nuestro horario de atención es de lunes a viernes de 9:00 a 18:00, y sábados de 9:00 a 13:00.'; }
    public function location(): string { return 'Estamos ubicados en Av. Principal 123. Próximamente podrás ver el mapa directamente aquí.'; }
    public function contact(): string { return 'Puedes contactarnos al +51 999 999 999 o al correo contacto@clinicadental.com.'; }
    public function askServiceForAppointment(): string { return '¡Perfecto! ¿Para qué servicio deseas agendar la cita?'; }
    public function serviceInfoConfirmationReminder(): string { return '¿Quieres que te ayude a agendar una cita para este servicio? Responde sí o no.'; }
    public function askServiceForAvailability(): string { return 'Claro. ¿Para qué servicio deseas consultar disponibilidad?'; }
    public function askDateForAvailability(string $service): string { return "Entendido, {$service}. ¿Para qué fecha deseas consultar disponibilidad?"; }
    public function askDateForAppointment(string $service): string { return "Entendido, {$service}. ¿Qué fecha te gustaría? (ej: 2026-08-25)"; }
    public function availableSlots(string $date, array $slots): string { return 'Para el '.$date.' tenemos disponibilidad a las: '.collect($slots)->pluck('time')->join(', ').'. ¿Cuál prefieres?'; }
    public function noAvailability(string $date): string { return "Lo siento, no encontramos horarios disponibles para el {$date}. ¿Deseas probar con otra fecha?"; }
    public function invalidDate(): string { return 'La fecha no es válida. Ingresa una fecha real en formato YYYY-MM-DD, por ejemplo: 2026-08-25.'; }
    public function invalidTime(): string { return 'La hora no es válida. Ingresa una hora en formato HH:MM, por ejemplo: 10:30.'; }
    public function unavailableTime(string $time, array $slots): string { return 'El horario '.$time.' no está disponible. Puedes elegir entre: '.collect($slots)->pluck('time')->join(', ').'.'; }
    public function confirmAppointmentRequest(array $appointment): string { return "Tu solicitud de cita para \"{$appointment['service']}\" el {$appointment['date']} a las {$appointment['time']} fue registrada (estado: {$appointment['status']}). Te confirmaremos la disponibilidad final a la brevedad."; }
    public function askAppointmentConfirmation(array $appointment): string { return "Tu solicitud de cita para \"{$appointment['service']}\" el {$appointment['date']} a las {$appointment['time']} está pendiente. ¿Confirmas la cita?"; }
    public function appointmentRequestFailed(): string
{
    return 'No pudimos registrar la solicitud de cita en este momento. Por favor, intenta nuevamente.';
}

    public function appointmentConfirmed(array $appointment): string { return "Tu cita para \"{$appointment['service']}\" el {$appointment['date']} a las {$appointment['time']} fue confirmada."; }
    public function appointmentConfirmationPrompt(): string { return 'Responde sí para confirmar la cita o no para cancelarla.'; }
    public function noAppointmentFound(): string { return 'No encontramos ninguna cita registrada a tu nombre. ¿Deseas agendar una nueva?'; }
    public function noAppointmentToReschedule(): string { return 'No encontré una cita para reprogramar.'; }
    public function appointmentFound(array $appointment): string { return "Tienes una cita de \"{$appointment['service']}\" el {$appointment['date']} a las {$appointment['time']} (estado: {$appointment['status']})."; }
    public function appointmentCancelled(): string { return 'Tu cita fue cancelada correctamente. Si deseas agendar una nueva, avísame.'; }
    public function appointmentRescheduled(array $appointment): string { return "Tu cita fue reprogramada para el {$appointment['date']} a las {$appointment['time']} (estado: {$appointment['status']})."; }
    public function humanHandoff(): string { return 'Entendido, en un momento un miembro de nuestro equipo continuará la conversación contigo. Por favor espera un momento. 🙏'; }
    public function helpMessage(): string { return 'Puedo ayudarte con información sobre servicios, horarios, ubicación y contacto. Escribe "hola" para comenzar o "hablar con una persona" para ser atendido por un agente.'; }
    public function conversationClosed(): string { return 'Esta conversación fue cerrada. Escríbenos de nuevo cuando quieras. 👋'; }
    public function unknown(): string { return 'Disculpa, no logré entender tu mensaje. ¿Podrías reformularlo? También puedo ponerte en contacto con una persona si lo prefieres.'; }

    public function forIntent(Intent $intent): string
    {
        return match ($intent) {
            Intent::GREETING => $this->greeting(), Intent::FAREWELL => $this->farewell(),
            Intent::GENERAL_INFO => $this->generalInfo(), Intent::SERVICES => $this->services(),
            Intent::PRICING => $this->pricing(), Intent::SCHEDULE => $this->schedule(),
            Intent::LOCATION => $this->location(), Intent::CONTACT => $this->contact(),
            Intent::HUMAN_HANDOFF => $this->humanHandoff(), default => $this->unknown(),
        };
    }
}
