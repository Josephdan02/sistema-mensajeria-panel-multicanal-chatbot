<?php

namespace Tests\Unit;

use App\Services\External\AI\GeminiAIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Pruebas del servicio Gemini con Http::fake: nunca se realizan llamadas
 * reales a la API de Google. La clave usada es un valor ficticio de prueba.
 */
class GeminiAIServiceTest extends TestCase
{
    private function configureGemini(): void
    {
        config([
            'services.gemini.api_key' => 'test-key-unit',
            'services.gemini.model' => 'gemini-test',
            'chatbot.gemini.system_instructions' => 'INSTRUCCIONES DE PRUEBA',
        ]);
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'respuesta de prueba']]]]],
            ]),
        ]);
    }

    public function test_request_includes_configured_system_instruction_and_keeps_generation_settings(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        $reply = (new GeminiAIService)->generateReply('hola');

        $this->assertSame('respuesta de prueba', $reply);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'gemini-test')
                && $request['systemInstruction']['parts'][0]['text'] === 'INSTRUCCIONES DE PRUEBA'
                && $request['generationConfig']['temperature'] === 0.2
                && $request['generationConfig']['maxOutputTokens'] === 300
                && $request->hasHeader('x-goog-api-key', 'test-key-unit');
        });
    }

    public function test_history_keeps_roles_and_order_and_hides_numeric_identifiers(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        (new GeminiAIService)->generateReply('y ahora qué?', [
            ['sender' => 'user', 'message' => 'Mi teléfono es 987654321'],
            ['sender' => 'bot', 'message' => 'Gracias. DNI 12345678A anotado'],
            ['sender' => 'user', 'message' => 'Referencias 1234567 y 123456789012'],
        ]);

        Http::assertSent(function (Request $request): bool {
            $contents = $request['contents'];

            if (count($contents) !== 4) {
                return false;
            }

            $roles = array_column($contents, 'role');
            if ($roles !== ['user', 'model', 'user', 'user']) {
                return false;
            }

            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $contents);
            $joined = implode(' | ', $texts);

            return ! str_contains($joined, '987654321')
                && ! str_contains($joined, '12345678A')
                && ! str_contains($joined, '1234567')
                && ! str_contains($joined, '123456789012')
                && substr_count($joined, '[dato oculto]') === 4
                && $texts[3] === 'y ahora qué?';
        });
    }

    public function test_history_is_limited_to_the_last_four_messages(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        $history = [];
        for ($i = 1; $i <= 15; $i++) {
            $history[] = ['sender' => 'user', 'message' => "mensaje {$i}"];
        }

        (new GeminiAIService)->generateReply('final', $history);

        Http::assertSent(function (Request $request): bool {
            $contents = $request['contents'];

            // Últimas 10 entradas del historial (mensaje 6 a 15) + el mensaje actual.
            return count($contents) === 5
                && $contents[0]['parts'][0]['text'] === 'mensaje 12'
                && $contents[3]['parts'][0]['text'] === 'mensaje 15'
                && $contents[4]['parts'][0]['text'] === 'final';
        });
    }

    public function test_empty_response_body_throws_runtime_exception(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => []]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini devolvió una respuesta inválida.');

        (new GeminiAIService)->generateReply('hola');
    }

    public function test_http_error_throws_runtime_exception(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('server error', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini devolvió un error HTTP.');

        (new GeminiAIService)->generateReply('hola');
    }

    public function test_missing_api_key_throws_without_sending_requests(): void
    {
        config(['services.gemini.api_key' => '']);
        Http::preventStrayRequests();
        Http::fake();

        try {
            (new GeminiAIService)->generateReply('hola');
            $this->fail('Debió lanzar RuntimeException por clave ausente.');
        } catch (RuntimeException $exception) {
            $this->assertSame('GEMINI_API_KEY no está configurada.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_current_message_with_document_number_is_sanitized(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        (new GeminiAIService)->generateReply('Mi DNI es 12345678, ¿me ayudas?');

        Http::assertSent(function (Request $request): bool {
            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $request['contents']);

            return ! str_contains(implode(' | ', $texts), '12345678')
                && str_contains($texts[0], '[dato oculto]');
        });
    }

    public function test_phone_with_separators_and_international_prefix_is_sanitized(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        (new GeminiAIService)->generateReply('Mi número es +51 999 888 777, llámame');

        Http::assertSent(function (Request $request): bool {
            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $request['contents']);
            $joined = implode(' | ', $texts);

            return ! str_contains($joined, '999')
                && ! str_contains($joined, '888')
                && str_contains($joined, '[dato oculto]');
        });
    }

    public function test_email_address_is_sanitized(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        (new GeminiAIService)->generateReply('Escríbeme a juan.perez@example.com por favor');

        Http::assertSent(function (Request $request): bool {
            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $request['contents']);
            $joined = implode(' | ', $texts);

            return ! str_contains($joined, 'juan.perez@example.com')
                && str_contains($joined, '[correo oculto]');
        });
    }

    public function test_sensitive_history_is_sanitized_but_stored_history_is_kept(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        $history = [
            ['sender' => 'user', 'message' => 'Mi correo es ana@test.com y mi DNI 87654321'],
        ];

        (new GeminiAIService)->generateReply('gracias', $history);

        // El historial original (en memoria) no se modifica.
        $this->assertSame('Mi correo es ana@test.com y mi DNI 87654321', $history[0]['message']);

        Http::assertSent(function (Request $request): bool {
            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $request['contents']);

            return str_contains($texts[0], '[correo oculto]')
                && str_contains($texts[0], '[dato oculto]')
                && ! str_contains($texts[0], 'ana@test.com')
                && ! str_contains($texts[0], '87654321');
        });
    }

    public function test_appointment_date_in_sent_copy_is_hidden_but_stored_message_is_kept(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        $history = [['sender' => 'user', 'message' => 'Tu cita es el 2026-08-25 a las 10:30']];

        (new GeminiAIService)->generateReply('confirmo', $history);

        // El historial original (en memoria) no se modifica: la fecha solo se
        // oculta en la copia enviada al proveedor.
        $this->assertSame('Tu cita es el 2026-08-25 a las 10:30', $history[0]['message']);

        Http::assertSent(function (Request $request): bool {
            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $request['contents']);

            return str_contains($texts[0], '[fecha omitida]')
                && ! str_contains($texts[0], '2026-08-25')
                && str_contains($texts[0], '10:30');
        });
    }

    public function test_bot_message_repeating_patient_identifiers_is_sanitized(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        (new GeminiAIService)->generateReply('gracias', [
            ['sender' => 'bot', 'message' => 'Anoté tu DNI 87654321 y tu correo ana@test.com'],
        ]);

        Http::assertSent(function (Request $request): bool {
            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $request['contents']);
            $joined = implode(' | ', $texts);

            return ! str_contains($joined, '87654321')
                && ! str_contains($joined, 'ana@test.com')
                && str_contains($joined, '[dato oculto]')
                && str_contains($joined, '[correo oculto]');
        });
    }

    public function test_phone_with_parentheses_is_sanitized(): void
    {
        $this->configureGemini();
        Http::preventStrayRequests();
        $this->fakeSuccess();

        (new GeminiAIService)->generateReply('Mi número es +51 (999) 888-777, llámame');

        Http::assertSent(function (Request $request): bool {
            $texts = array_map(fn (array $entry): string => $entry['parts'][0]['text'], $request['contents']);
            $joined = implode(' | ', $texts);

            return ! str_contains($joined, '999')
                && ! str_contains($joined, '888')
                && str_contains($joined, '[dato oculto]');
        });
    }
}