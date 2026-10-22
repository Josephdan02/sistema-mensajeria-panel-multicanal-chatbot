<?php

namespace App\Services\External\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiAIService implements AIServiceInterface
{
    public function generateReply(string $message, array $history = []): string
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') throw new RuntimeException('GEMINI_API_KEY no está configurada.');

        $contents = array_map(fn (array $entry): array => [
            'role' => $entry['sender'] === 'bot' ? 'model' : 'user',
            // Decisión 5H.4 (opción a): los mensajes del propio bot también se
            // sanitizan en la copia de envío (pueden repetir un DNI o teléfono
            // dictado por el usuario). Fase 5I: las fechas YYYY-MM-DD se
            // ocultan y las horas cortas se conservan dentro de sanitizeText().
            'parts' => [['text' => $this->sanitizeText($entry['message'] ?? '')]],
        ], $this->recentHistory($history));
        $contents[] = ['role' => 'user', 'parts' => [['text' => $this->sanitizeText($message)]]];
        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');
        $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', rawurlencode($model));

        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 300],
        ];

        $instructions = trim((string) config('chatbot.gemini.system_instructions', ''));
        if ($instructions !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $instructions]]];
        }

        try {
            $response = Http::timeout(8)->acceptJson()->withHeaders([
                'x-goog-api-key' => $apiKey,
            ])->post($url, $payload);
            $response->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException('Gemini devolvió un error HTTP.', 0, $exception);
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') throw new RuntimeException('Gemini devolvió una respuesta inválida.');
        return trim($text);
    }

    /**
     * Últimas N entradas del historial (límite configurable, 4 por defecto).
     * Devuelve la porción sin modificar: la sanitización se aplica después,
     * sobre la copia que se envía al proveedor.
     *
     * @param array<int, array{sender: string, message: string}> $history
     * @return array<int, array{sender: string, message: string}>
     */
    private function recentHistory(array $history): array
    {
        $limit = (int) config('chatbot.gemini.history_limit', 4);
        if ($limit < 0) {
            $limit = 0;
        }

        return array_values(array_slice($history, -$limit));
    }

    /**
     * Copia de un texto lista para Gemini: oculta correos electrónicos,
     * fechas con formato YYYY-MM-DD y secuencias de 7 a 12 dígitos
     * (posibles DNI, teléfonos u otros identificadores), incluidos números
     * con espacios, puntos o guiones y con prefijo internacional opcional
     * (p. ej. +51 999 888 777).
     *
     * Límites conocidos: no detecta nombres, direcciones ni identificadores
     * no numéricos; un número de 6 o menos dígitos no se oculta; solo se
     * ocultan fechas con el formato YYYY-MM-DD, no otras variantes. Los
     * mensajes originales de la conversación no se modifican y los valores
     * ocultos no se registran.
     */
    private function sanitizeText(string $text): string
    {
        $text = (string) preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            '[correo oculto]',
            $text
        );

        $text = (string) preg_replace(
            '/\b\d{4}-\d{2}-\d{2}\b/',
            '[fecha omitida]',
            $text
        );

        $text = (string) preg_replace_callback(
            '/(?<!\d)\+?\(?\d(?:[\s.\-()]*\d){6,11}(?!\d)/',
            function (array $matches): string {
                $candidate = $matches[0];
                $digits = (string) preg_replace('/\D/', '', $candidate);
                $count = strlen($digits);

                if ($count < 7 || $count > 12) {
                    return $candidate;
                }

                // Conservar horas cortas (HH:MM); las fechas YYYY-MM-DD ya se
                // ocultaron antes como [fecha omitida].
                if (preg_match('/\d{4}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*\d{1,2}/', $candidate) === 1) {
                    return $candidate;
                }

                return '[dato oculto]';
            },
            $text
        );

        return $text;
    }

    }
