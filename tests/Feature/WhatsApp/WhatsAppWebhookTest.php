<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;

/**
 * Webhook de WhatsApp Cloud API: verificación GET, firma POST, idempotencia,
 * canal de la conversación y silencio del bot bajo control de agente.
 */
class WhatsAppWebhookTest extends WhatsAppTestCase
{
    // ------------------------------------------------------------------
    // Verificación GET (Meta)
    // ------------------------------------------------------------------

    public function test_verification_returns_the_challenge_with_the_correct_token(): void
    {
        $this->useCloudApiWhatsApp();

        $response = $this->get('/api/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => self::VERIFY_TOKEN,
            'hub.challenge' => '1234567890',
        ]));

        $response->assertOk();
        $this->assertSame('1234567890', $response->getContent());
    }

    public function test_verification_is_rejected_with_a_wrong_token(): void
    {
        $this->useCloudApiWhatsApp();

        $this->get('/api/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong-token',
            'hub.challenge' => '1234567890',
        ]))->assertStatus(403);
    }

    public function test_verification_is_rejected_without_verify_token_configuration(): void
    {
        config()->set('services.whatsapp.verify_token', '');

        $this->get('/api/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => '',
            'hub.challenge' => '1234567890',
        ]))->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Firma POST (X-Hub-Signature-256)
    // ------------------------------------------------------------------

    public function test_webhook_is_rejected_without_signature(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $raw = json_encode($this->inboundTextPayload('5491122334455', 'hola'), JSON_UNESCAPED_UNICODE);

        $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(403);

        $this->assertSame(0, Conversation::count());
    }

    public function test_webhook_is_rejected_with_invalid_signature(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $this->postWebhook($this->inboundTextPayload('5491122334455', 'hola'), 'otro-secret')
            ->assertStatus(403);

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
    }

    // ------------------------------------------------------------------
    // Flujo entrante: WhatsApp -> Conversation -> ChatbotService
    // ------------------------------------------------------------------

    public function test_incoming_text_message_creates_whatsapp_conversation_and_sends_bot_reply(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $this->postWebhook($this->inboundTextPayload('549115550001', 'hola'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $conversation = Conversation::query()
            ->where('channel', 'whatsapp')
            ->where('user_identifier', '549115550001')
            ->firstOrFail();

        $this->assertSame(ConversationState::IDLE, $conversation->state);

        // Mensaje del cliente + respuesta del bot persistidos.
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());

        // La respuesta del bot salió por Cloud API hacia el número.
        $this->assertWhatsAppSentCount(1);
        $this->assertWhatsAppSent('549115550001');
    }

    public function test_second_message_reuses_the_same_whatsapp_conversation(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $this->postWebhook($this->inboundTextPayload('549115550001', 'hola', 'wamid.A1'))->assertOk();
        $this->postWebhook($this->inboundTextPayload('549115550001', 'quiero agendar una cita', 'wamid.A2'))
            ->assertOk();

        // Misma conversación reutilizada: 2 entrantes x (cliente + bot) = 4.
        $conversation = Conversation::where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame(1, Conversation::where('channel', 'whatsapp')->count());
        $this->assertSame(4, $conversation->messages()->count());
        $this->assertSame(2, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
    }

    public function test_web_conversation_with_same_identifier_is_not_touched(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $web = $this->conversation(['user_identifier' => '549115550001']);

        $this->postWebhook($this->inboundTextPayload('549115550001', 'hola'))->assertOk();

        $web->refresh();
        $this->assertSame('web', $web->channel);
        $this->assertSame(0, $web->messages()->count());

        $whatsapp = Conversation::query()->where('channel', 'whatsapp')->firstOrFail();
        $this->assertNotSame($web->id, $whatsapp->id);
    }

    public function test_duplicate_webhook_delivery_is_processed_only_once(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $payload = $this->inboundTextPayload('549115550001', 'hola', 'wamid.DUP1');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        // Una sola conversación, un solo mensaje del cliente, un solo envío.
        $this->assertSame(1, Conversation::where('channel', 'whatsapp')->count());
        $this->assertSame(1, Message::where('sender', Message::SENDER_USER)->count());
        $this->assertWhatsAppSentCount(1);
    }

    public function test_non_text_and_status_payloads_are_ignored_safely(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        // Mensaje adjunto (fuera del alcance del MVP): se ignora sin romper.
        $image = $this->inboundTextPayload('549115550001', 'x', 'wamid.IMG1');
        $image['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from' => '549115550001',
            'id' => 'wamid.IMG1',
            'timestamp' => '1758000000',
            'type' => 'image',
            'image' => ['id' => 'media123', 'mime_type' => 'image/jpeg'],
        ];

        $this->postWebhook($image)->assertOk();

        // Status callback (entregado/leído): sin mensajes, se ignora.
        $status = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [[
                            'id' => 'wamid.STATUS1',
                            'status' => 'delivered',
                            'recipient_id' => '549115550001',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $this->postWebhook($status)->assertOk();

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
        $this->assertWhatsAppSentCount(0);
    }

    // ------------------------------------------------------------------
    // Silencio del bot bajo control de agente (mismo contrato que web)
    // ------------------------------------------------------------------

    public function test_bot_is_silent_when_the_whatsapp_conversation_is_waiting_agent(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $conversation = $this->conversation([
            'user_identifier' => '549115550002',
            'channel' => 'whatsapp',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->postWebhook($this->inboundTextPayload('549115550002', '¿Hay alguien?'))->assertOk();

        $conversation->refresh();
        $this->assertSame(ConversationState::WAITING_AGENT, $conversation->state);
        $this->assertSame(1, $conversation->unreadMessagesCount());
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertWhatsAppSentCount(0);
    }

    public function test_bot_is_silent_when_the_whatsapp_conversation_is_assigned_or_attending(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $agent = $this->agent();

        foreach ([ConversationState::ASSIGNED, ConversationState::ATTENDING] as $index => $state) {
            $number = '54911555001'.$index;
            $conversation = $this->conversation([
                'user_identifier' => $number,
                'channel' => 'whatsapp',
                'state' => $state,
                'assigned_agent_id' => $agent->id,
                'assigned_at' => now()->subMinutes(5),
            ]);

            $this->postWebhook($this->inboundTextPayload($number, 'Sigo esperando', 'wamid.'.$state->value))
                ->assertOk();

            $conversation->refresh();
            $this->assertSame($state, $conversation->state);
            $this->assertSame(1, $conversation->messages()->count());
        }

        $this->assertWhatsAppSentCount(0);
    }

    public function test_bot_notice_is_sent_when_the_conversation_is_in_handoff(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $conversation = $this->conversation([
            'user_identifier' => '549115550003',
            'channel' => 'whatsapp',
            'state' => ConversationState::HANDOFF_TO_HUMAN,
        ]);

        $this->postWebhook($this->inboundTextPayload('549115550003', '¿Alguien me atiende?'))->assertOk();

        $conversation->refresh();
        $this->assertSame(ConversationState::HANDOFF_TO_HUMAN, $conversation->state);

        // El aviso "ya estás en contacto con el equipo" sí sale por WhatsApp
        // (comportamiento original de HANDOFF_TO_HUMAN preservado).
        $this->assertWhatsAppSentCount(1);
        $this->assertWhatsAppSent('549115550003');
    }

    // ------------------------------------------------------------------
    // Errores controlados
    // ------------------------------------------------------------------

    public function test_webhook_returns_200_when_graph_api_rejects_the_send(): void
    {
        $this->useCloudApiWhatsApp();

        // Graph devuelve error: el webhook no debe fallar ni duplicar datos.
        // Stub global para no tener fugas de red hacia otras APIs.
        Http::fake(function ($request): \Illuminate\Http\Client\Response {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                return Http::response(['error' => ['message' => 'Server Error']], 500);
            }

            return Http::response();
        });

        $this->postWebhook($this->inboundTextPayload('549115550004', 'hola'))
            ->assertOk()
            ->assertJsonPath('success', true);

        // El mensaje del cliente quedó persistido aunque el envío falle.
        $conversation = Conversation::query()->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
    }
}