<?php

namespace App\Services\External\AI;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Segunda barrera determinista para respuestas del proveedor de IA.
 *
 * Solo se aplica al texto que Gemini devuelve en la rama unknownResponse()
 * de ChatbotService. Nunca se evalúa sobre el catálogo oficial
 * (ResponseGenerator::serviceInfo) ni sobre mensajes de agentes humanos.
 *
 * Filosofía conservadora: ante la duda el texto se conserva. Cuando un
 * patrón coincide, la respuesta completa se sustituye por una redirección
 * segura (nunca se conserva visible el dato no verificado con un simple
 * descargo añadido).
 */
class AiReplyGuard
{
    public function guard(string $reply): string
    {
        $normalized = Str::ascii(Str::lower(Str::squish($reply)));

        if ($this->matchesAny($normalized, (array) config('chatbot.gemini.ai_reply_guard.diagnosis', []), 'diagnosis')) {
            return 'Solo la clínica puede evaluar tu caso y confirmar un diagnóstico. Escríbenos "hablar con una persona" o contáctanos para una evaluación profesional.';
        }

        if ($this->matchesAny($normalized, (array) config('chatbot.gemini.ai_reply_guard.guarantee', []), 'guarantee')) {
            return 'Los resultados de cada tratamiento dependen de una evaluación profesional y no pueden garantizarse por este medio. Un miembro del equipo puede orientarte sobre tu caso.';
        }

        if ($this->matchesAny($normalized, (array) config('chatbot.gemini.ai_reply_guard.agenda_claim', []), 'agenda_claim')) {
            return 'Esta respuesta no confirma ninguna gestión de citas. Para crear, cancelar o reprogramar una cita debes hacerlo con el personal de la clínica.';
        }

        if ($this->hasUnverifiedCommercialData($normalized)) {
            return 'Precios, promociones, horarios y duración deben confirmarse con la clínica o con un agente. Escríbenos "5" para consultar los servicios o "hablar con una persona" para continuar.';
        }

        return $reply;
    }

    private function matchesAny(string $normalized, array $patterns, string $rule): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            $matched = @preg_match('/'.$pattern.'/i', $normalized);

            // Patrón inválido: no ignorarlo en silencio. Se registra la regla
            // afectada (sin contenido del paciente) y se continúa evaluando
            // el resto. Nunca se muestra nada al usuario por esta causa.
            if ($matched === false) {
                Log::warning('AiReplyGuard: patrón inválido ignorado.', ['rule' => $rule]);

                continue;
            }

            if ($matched === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Datos comerciales u operativos presentados como hechos concretos sin
     * confirmación: cifras con moneda (en dígitos o en palabras), porcentajes
     * de descuento (con símbolo o con palabras), horarios con contexto de
     * atención o duraciones. Las menciones genéricas sin cifras concretas
     * ("tenemos precios accesibles") se conservan. Una hora HH:MM aislada o
     * un rango de días sin cifras ("de lunes a viernes") se conservan: solo
     * se sustituyen cuando hay dígitos MÁS contexto de atención/horario.
     */
    private function hasUnverifiedCommercialData(string $normalized): bool
    {
        if (preg_match('/\b\d[\d\s.,]*\s*(s\/\.|soles?|usd|dolares?|\$|€|£)\b/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(s\/\.?|\$|€|£|usd|soles?|dolares?)\s*\d/', $normalized) === 1) {
            return true;
        }

        // Precio expresado con palabras ("trescientos soles").
        if (preg_match('/\b(uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieciseis|diecisiete|dieciocho|diecinueve|veinte|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|ciento|cientos|doscientos|trescientos|cuatrocientos|quinientos|seiscientos|setecientos|ochocientos|novecientos|mil)\b.{0,16}\b(soles?|s\/\.?|dolares?|usd|\$|€|£)\b/', $normalized) === 1) {
            return true;
        }

        // Descuento con símbolo o con palabras ("20% de descuento",
        // "20 por ciento de descuento").
        if (preg_match('/\b\d+\s*(%|por\s*ciento)\s*(de\s+)?(descuento|desct[oa]|oferta|promoci[oó]n)/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(oferta|promoci[oó]n|descuento)\b.{0,40}\b\d/', $normalized) === 1) {
            return true;
        }

        if ($this->hasUnverifiedSchedule($normalized)) {
            return true;
        }

        if (preg_match('/\b\d+\s*(minutos?|horas?|sesiones?|sesion|d[ií]as?|semanas?|meses?)\b.{0,30}\b(dura|tarda|demora|demorar|tratamiento|procedimiento|cita|sesi[oó]n)\b|\b(dura|tarda|demora|demorar)\b.{0,30}\b\d+\s*(minutos?|horas?|sesiones?|sesion|d[ií]as?|semanas?|meses?)\b|\b\d+\s*(minutos?|horas?)\b\s*por\s*sesi[oó]n\b/', $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Horario no verificado: exige dígitos MÁS contexto próximo de atención.
     * "Escríbenos a las 10:30" (hora sin contexto de horario) o
     * "de lunes a viernes" sin cifras se conservan; "atendemos de lunes a
     * viernes de 9 a 18" o "abrimos a las 9:00" se sustituyen.
     */
    private function hasUnverifiedSchedule(string $normalized): bool
    {
        if (preg_match('/\b\d/', $normalized) !== 1) {
            return false;
        }

        if (preg_match('/\b(horario|horarios|hora|horas|abrimos|abierto|abierta|cerramos|cerrado|atendemos|atienden|atencion|atención|consulta|consultas|cita|citas|turno|turnos|lunes|martes|miercoles|miércoles|jueves|viernes|sabado|sábado|domingo|semana|manana|mañana|tarde|noche)\b/', $normalized) !== 1) {
            return false;
        }

        if (preg_match('/\b\d{1,2}:\d{2}\b/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\bde\s+\d{1,2}\s*a\s+\d{1,2}\b/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\bde\s+lunes\s+a\s+\w+\b.{0,40}\b\d|\b\d.{0,40}\bde\s+lunes\s+a\s+\w+\b/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\bhorarios?\b.{0,30}\b\d/', $normalized) === 1) {
            return true;
        }

        return false;
    }
}
