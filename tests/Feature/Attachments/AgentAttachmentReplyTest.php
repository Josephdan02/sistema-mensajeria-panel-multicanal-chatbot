<?php

namespace Tests\Feature\Attachments;

use App\Enums\AttachmentStatus;
use App\Enums\ConversationState;
use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Envío de adjuntos desde el panel del agente.
 *
 * Todo el tráfico de Telegram se simula con Http::fake(); el almacenamiento
 * usa el doble en memoria de AttachmentTestCase.
 */
class AgentAttachmentReplyTest extends AttachmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.api_url', 'https://api.telegram.org/bot');
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.timeout', 5);
    }

    public function test_agent_can_send_an_image_with_text_and_emoji(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.reply', $conversation), [
                'message' => 'Resultado de la radiografía 😊',
                'attachment' => UploadedFile::fake()->createWithContent('radiografia.png', $this->pngBytes()),
            ])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame(ConversationState::ATTENDING, $conversation->state);

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender', Message::SENDER_AGENT)
            ->firstOrFail();

        $this->assertSame('Resultado de la radiografía 😊', $message->message);

        $attachment = Attachment::query()->where('message_id', $message->id)->firstOrFail();

        $this->assertSame('image', $attachment->kind);
        $this->assertSame(AttachmentStatus::AVAILABLE, $attachment->status);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('radiografia.png', $attachment->original_name);
        $this->assertStringStartsWith('agent/image/', (string) $attachment->storage_path);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'name="chat_id"')
            && str_contains($request->body(), '12389')
            && str_contains($request->body(), 'name="caption"')
            && str_contains($request->body(), 'Resultado de la radiografía 😊')
            && str_contains($request->body(), 'radiografia.png'));
    }

    public function test_agent_can_send_an_attachment_without_text(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.reply', $conversation), [
                'attachment' => UploadedFile::fake()->createWithContent(
                    'informe.pdf',
                    "%PDF-1.4\n% documento de prueba\n",
                ),
            ])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender', Message::SENDER_AGENT)
            ->firstOrFail();

        $this->assertSame('', $message->message);

        $attachment = Attachment::query()->where('message_id', $message->id)->firstOrFail();

        $this->assertSame('document', $attachment->kind);
        $this->assertSame('application/pdf', $attachment->mime_type);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')
            && str_contains($request->body(), 'name="chat_id"')
            && str_contains($request->body(), '12389')
            && ! str_contains($request->body(), 'name="caption"'));
    }

    public function test_gif_is_sent_as_animation(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.reply', $conversation), [
                'attachment' => UploadedFile::fake()->createWithContent(
                    'animacion.gif',
                    "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
                ),
            ])
            ->assertRedirect();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendAnimation'));
    }

    public function test_rejected_telegram_file_is_not_persisted(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);

        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->withHeader('Accept', 'application/json')
            ->post(route('agent.conversations.reply', $conversation), [
                'message' => 'Con adjunto',
                'attachment' => UploadedFile::fake()->createWithContent('foto.png', $this->pngBytes()),
            ])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender', Message::SENDER_AGENT)
            ->count());
        $this->assertSame(0, Attachment::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_ambiguous_telegram_result_is_not_persisted(): void
    {
        Http::fake(fn () => Http::failedConnection('Connection timed out'));

        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->withHeader('Accept', 'application/json')
            ->post(route('agent.conversations.reply', $conversation), [
                'attachment' => UploadedFile::fake()->createWithContent('foto.png', $this->pngBytes()),
            ])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender', Message::SENDER_AGENT)
            ->count());
        $this->assertSame(0, Attachment::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_disallowed_file_is_rejected_before_sending(): void
    {
        Http::fake();

        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->withHeader('Accept', 'application/json')
            ->post(route('agent.conversations.reply', $conversation), [
                'attachment' => UploadedFile::fake()->create('ejecutable.exe', 10, 'application/octet-stream'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        Http::assertNothingSent();
        $this->assertSame(0, Message::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_empty_message_without_attachment_is_rejected(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->withHeader('Accept', 'application/json')
            ->post(route('agent.conversations.reply', $conversation), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_text_only_reply_still_works_with_emoji(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->postJson(route('agent.conversations.reply', $conversation), [
                'message' => 'Cita confirmada ✅ 🦷',
            ])
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_AGENT,
            'message' => 'Cita confirmada ✅ 🦷',
        ]);
    }

    public function test_opus_voice_file_is_accepted_and_sent_as_document(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.reply', $conversation), [
                'attachment' => UploadedFile::fake()->createWithContent(
                    'nota-de-voz.opus',
                    "OggS\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x1f\x00\x00\x00\x00\x00\x00OpusHead\x01\x01\x00\x00\x80\xbb\x00\x00\x00\x00\x00",
                ),
            ])
            ->assertRedirect();

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender', Message::SENDER_AGENT)
            ->firstOrFail();

        $attachment = Attachment::query()->where('message_id', $message->id)->firstOrFail();

        $this->assertSame('audio', $attachment->kind);
        $this->assertSame('opus', $attachment->extension);
        $this->assertSame('audio/ogg', $attachment->mime_type);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'));
    }

    /** PNG mínimo válido (1x1). */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
