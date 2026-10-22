<?php

namespace App\Services\Intent;

use App\Enums\Intent;
use App\Models\Conversation;
use Illuminate\Support\Str;

class RuleBasedIntentDetector implements IntentDetectorInterface
{
    /** @var array<string, array<int, array{pattern: string, score: int}>> */
    private array $patterns = [
        'cancel_appointment' => [
            ['pattern' => '/\bcancelar(?:\s+\w+){0,5}\s+cita\b/u', 'score' => 100],
            ['pattern' => '/\banular(?:\s+\w+){0,5}\s+cita\b/u', 'score' => 100],
            ['pattern' => '/^\s*4\s*$/u', 'score' => 85],
        ],
        'reschedule_appointment' => [
            ['pattern' => '/\b(?:cambiar|reprogramar|mover|modificar)(?:\s+\w+){0,5}\s+cita\b/u', 'score' => 100],
            ['pattern' => '/^\s*3\s*$/u', 'score' => 85],
        ],
        'request_appointment' => [
            ['pattern' => '/\b(?:agendar|reservar|programar|solicitar)\s+(?:una\s+)?cita\b/u', 'score' => 95],
            ['pattern' => '/\bsacar(?:\s+una)?\s+cita\b/u', 'score' => 95],
            ['pattern' => '/\bquiero\s+(?:una\s+)?cita\b/u', 'score' => 95],
            ['pattern' => '/^\s*2\s*$/u', 'score' => 85],
        ],
        'check_appointment' => [
            ['pattern' => '/\b(?:consultar|ver|tengo|cu[aá]l\s+es)\s+(?:mi\s+)?cita\b/u', 'score' => 90],
            ['pattern' => '/\ba\s+qu[eé]\s+hora\s+(?:tengo|es)\s+(?:mi\s+)?cita\b/u', 'score' => 90],
            ['pattern' => '/\b(?:consultar|ver|saber)\s+(?:mis\s+|mi\s+|las\s+)?citas\b/u', 'score' => 90],
            ['pattern' => '/\b(?:cu[aá]les\s+son\s+(?:mis\s+)?citas|qu[eé]\s+citas?\s+tengo|tengo\s+citas)\b/u', 'score' => 90],
            ['pattern' => '/^\s*1\s*$/u', 'score' => 85],
        ],
        'availability' => [
            ['pattern' => '/\b(?:disponibilidad|horarios?\s+disponibles|qu[eé]\s+horarios?\s+tienen)\b/u', 'score' => 80],
            ['pattern' => '/^\s*6\s*$/u', 'score' => 85],
        ],
        'pricing' => [
            ['pattern' => '/\b(?:precio|precios|cu[aá]nto\s+cuesta|costo|cu[aá]nto\s+sale|tarifa)\b/u', 'score' => 70],
        ],
        'location' => [
            ['pattern' => '/\b(?:ubicaci[oó]n|ubicados?|direcci[oó]n|d[oó]nde\s+(?:est[aá]n|quedan)|c[oó]mo\s+llegar)\b/u', 'score' => 70],
        ],
        'schedule' => [
            ['pattern' => '/\b(?:horarios?|abren|cierran|atienden)\b/u', 'score' => 50],
        ],
        'human_handoff' => [
            ['pattern' => '/\b(?:hablar\s+con\s+(?:una\s+)?persona|hablar\s+con\s+alguien|hablar\s+con\s+(?:un|alg[uú]n)\s+miembro\s+del\s+personal|hablar\s+con\s+el\s+personal|quiero\s+(?:hablar\s+con\s+)?(?:un\s+)?asesor|necesito\s+un\s+asesor|agente\s+humano|persona\s+real|operador)\b/u', 'score' => 100],
        ],
        'greeting' => [
            ['pattern' => '/\b(?:hola|buenas|hey|buenos\s+d[ií]as|buenas\s+(?:tardes|noches)|saludos)\b/u', 'score' => 20],
            ['pattern' => '/\bbuen\s+d[ií]a\b/u', 'score' => 20],
        ],
        'farewell' => [
            ['pattern' => '/\b(?:ad[ií]os|chau|hasta\s+luego|nos\s+vemos|bye)\b/u', 'score' => 20],
        ],
        'services' => [
            ['pattern' => '/\b(?:servicios|tratamientos|qu[eé]\s+hacen|qu[eé]\s+ofrecen)\b/u', 'score' => 40],
            ['pattern' => '/^\s*5\s*$/u', 'score' => 85],
        ],
        'general_info' => [
            ['pattern' => '/\b(?:informaci[oó]n|info|quisiera\s+saber|me\s+pueden\s+contar)\b/u', 'score' => 30],
        ],
        'contact' => [
            ['pattern' => '/\b(?:contacto|tel[eé]fono|n[uú]mero|correo|email)\b/u', 'score' => 40],
        ],
    ];

    public function detect(string $message, Conversation $conversation): Intent
    {
        $normalized = $this->normalize($message);
        $scores = [];

        foreach ($this->patterns as $intentValue => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern['pattern'], $normalized) === 1) {
                    $scores[$intentValue] = max($scores[$intentValue] ?? 0, $pattern['score']);
                }
            }
        }

        if ($scores === []) {
            return Intent::UNKNOWN;
        }

        arsort($scores);

        return Intent::from(array_key_first($scores));
    }

    private function normalize(string $text): string
    {
        $normalized = Str::lower(Str::squish($text));

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }
}
