<?php

namespace Tests\Feature\Attachments;

use App\Enums\AttachmentStatus;
use App\Enums\ConversationState;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\External\Telegram\TelegramApiService;
use App\Services\External\Telegram\TelegramServiceInterface;
use Illuminate\Support\Facades\Http;

/**
 * Recepción de adjuntos por el webhook de Telegram.
 *
 * Todo el tráfico se simula con `Http::fake()`: no se contacta con Telegram
 * ni con Supabase.
 */
class TelegramAttachmentIngestTest extends AttachmentTestCase
{
    protected const WEBHOOK_SECRET = 'telegram-webhook-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.webhook_secret', self::WEBHOOK_SECRET);
        config()->set('services.telegram.api_url', 'https://api.telegram.org/bot');
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.timeout', 5);
        $this->app->instance(TelegramServiceInterface::class, new TelegramApiService);

        // Conversación previa en estado "en manos de un agente": es el
        // escenario en el que el bot registra el adjunto del cliente.
        $this->conversation();
    }

    public function test_valid_image_is_stored_and_linked_to_the_message(): void
    {
        $this->fakeTelegram($this->pngBytes());

        $conversation = $this->conversation();
        $this->postWebhook($this->photoUpdate('photo-1', 'photo-uniq-1'))->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'getFile'));

        $attachment = Attachment::query()->where('telegram_file_id', 'photo-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::AVAILABLE, $attachment->status);
        $this->assertSame('image', $attachment->kind);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame($conversation->id, $attachment->conversation_id);
        $this->assertNotNull($attachment->message_id);
        $this->assertNotNull($attachment->storage_path);
        $this->assertSame($this->pngBytes(), $this->storedContent);

        // El texto descriptivo existente se conserva.
        $message = Message::query()->findOrFail($attachment->message_id);
        $this->assertStringContainsString('foto', $message->message);
    }

    public function test_allowed_document_is_stored(): void
    {
        $this->fakeTelegram("%PDF-1.4\n% documento de prueba\n");

        $this->postWebhook($this->documentUpdate('doc-1', 'informe.pdf', 'application/pdf'))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'doc-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::AVAILABLE, $attachment->status);
        $this->assertSame('document', $attachment->kind);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('informe.pdf', $attachment->original_name);
    }

    public function test_voice_note_is_stored_as_audio(): void
    {
        $this->fakeTelegram($this->oggBytes());

        $this->postWebhook($this->voiceUpdate('voice-1'))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'voice-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::AVAILABLE, $attachment->status);
        $this->assertSame('audio', $attachment->kind);
        $this->assertSame('audio/ogg', $attachment->mime_type);
    }

    public function test_disallowed_format_is_rejected(): void
    {
        $this->fakeTelegram('contenido ejecutable');

        $this->postWebhook($this->documentUpdate('exe-1', 'virus.exe', 'application/octet-stream'))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'exe-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::FAILED, $attachment->status);
        $this->assertNull($attachment->storage_path);
        $this->assertStringContainsString('no está permitido', (string) $attachment->error_message);
    }

    public function test_svg_is_rejected(): void
    {
        $this->fakeTelegram('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->postWebhook($this->documentUpdate('svg-1', 'logo.svg', 'image/svg+xml'))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'svg-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::FAILED, $attachment->status);
        $this->assertNull($attachment->storage_path);
    }

    public function test_file_exceeding_the_limit_is_rejected(): void
    {
        $this->fakeTelegram($this->pngBytes());

        $this->postWebhook($this->photoUpdate('big-1', 'big-uniq-1', 6 * 1024 * 1024))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'big-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::TOO_LARGE, $attachment->status);
        $this->assertNull($attachment->storage_path);
        $this->assertStringContainsString('tamaño máximo', (string) $attachment->error_message);
    }

    public function test_content_not_matching_its_extension_is_rejected(): void
    {
        // Se anuncia como PDF pero el contenido es un PNG real.
        $this->fakeTelegram($this->pngBytes());

        $this->postWebhook($this->documentUpdate('fake-1', 'informe.pdf', 'application/pdf'))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'fake-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::FAILED, $attachment->status);
        $this->assertNull($attachment->storage_path);
        $this->assertStringContainsString('coincide', (string) $attachment->error_message);
    }

    public function test_telegram_failure_marks_the_attachment_as_failed(): void
    {
        Http::fake([
            'api.telegram.org/*/getFile' => Http::response(['ok' => false, 'description' => 'Not Found'], 404),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postWebhook($this->photoUpdate('missing-1', 'missing-uniq-1'))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'missing-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::UNAVAILABLE, $attachment->status);
        $this->assertNull($attachment->storage_path);
        $this->assertStringContainsString('no está disponible', (string) $attachment->error_message);
    }

    public function test_duplicate_update_does_not_create_a_second_attachment(): void
    {
        $this->fakeTelegram($this->pngBytes());

        $payload = $this->photoUpdate('dup-1', 'dup-uniq-1');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, Attachment::query()->where('telegram_file_id', 'dup-1')->count());
    }

    public function test_same_file_in_a_different_update_is_not_duplicated(): void
    {
        $this->fakeTelegram($this->pngBytes());

        $this->postWebhook($this->photoUpdate('same-1', 'same-uniq-1', null, 9001))->assertOk();
        $this->postWebhook($this->photoUpdate('same-1', 'same-uniq-1', null, 9002))->assertOk();

        $this->assertSame(1, Attachment::query()->where('telegram_file_id', 'same-1')->count());
    }

    public function test_storage_failure_keeps_the_webhook_working_and_hides_secrets(): void
    {
        $this->fakeTelegram($this->pngBytes());
        $this->storageFails = true;

        $this->postWebhook($this->photoUpdate('fail-1', 'fail-uniq-1'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $attachment = Attachment::query()->where('telegram_file_id', 'fail-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::FAILED, $attachment->status);
        $this->assertNull($attachment->storage_path);
        $this->assertStringNotContainsString('service_role', (string) $attachment->error_message);
        $this->assertStringNotContainsString('test-bot-token', (string) $attachment->error_message);
    }

    public function test_text_messages_and_handoff_behaviour_are_unchanged(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postWebhook([
            'update_id' => 7001,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 12390, 'type' => 'private'],
                'text' => 'hola',
            ],
        ])->assertOk();

        $conversation = Conversation::query()->where('user_identifier', '12390')->firstOrFail();

        $this->assertSame(ConversationState::IDLE, $conversation->state);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => 'hola',
        ]);
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_attachment_while_an_agent_is_in_charge_is_recorded_without_bot_reply(): void
    {
        $this->fakeTelegram($this->pngBytes());

        // Conversación en manos de un agente humano (bot callado).
        $conversation = Conversation::query()->where('user_identifier', '12389')->firstOrFail();
        $conversation->state = ConversationState::ASSIGNED;
        $conversation->save();

        $this->postWebhook($this->photoUpdate('agent-1', 'agent-uniq-1', null, 7002))->assertOk();

        $attachment = Attachment::query()->where('telegram_file_id', 'agent-1')->firstOrFail();

        $this->assertSame(AttachmentStatus::AVAILABLE, $attachment->status);
        $this->assertSame($conversation->id, $attachment->conversation_id);
        $this->assertSame(1, $conversation->unreadMessagesCount());
    }

    // --- Utilidades --------------------------------------------------------

    private function fakeTelegram(string $contents): void
    {
        Http::fake(function ($request) use ($contents) {
            $url = $request->url();

            if (str_contains($url, '/getFile')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'file_id' => 'file-id',
                        'file_unique_id' => 'file-unique-id',
                        'file_path' => 'documents/file.pdf',
                        'file_size' => strlen($contents),
                    ],
                ], 200);
            }

            if (str_contains($url, '/file/')) {
                return Http::response($contents, 200);
            }

            return Http::response(['ok' => true], 200);
        });
    }

    private function postWebhook(array $payload)
    {
        return $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => self::WEBHOOK_SECRET,
        ])->postJson('/api/telegram/webhook', $payload);
    }

    private function photoUpdate(string $fileId, string $uniqueId, ?int $size = null, int $updateId = 7001): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 12389, 'type' => 'private'],
                'photo' => [
                    ['file_id' => $fileId, 'file_unique_id' => $uniqueId, 'width' => 100, 'height' => 100, 'file_size' => $size],
                ],
            ],
        ];
    }

    private function documentUpdate(string $fileId, string $fileName, string $mimeType): array
    {
        return [
            'update_id' => 7010,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 12389, 'type' => 'private'],
                'document' => [
                    'file_id' => $fileId,
                    'file_unique_id' => $fileId.'-unique',
                    'file_name' => $fileName,
                    'mime_type' => $mimeType,
                ],
            ],
        ];
    }

    private function voiceUpdate(string $fileId): array
    {
        return [
            'update_id' => 7020,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 12389, 'type' => 'private'],
                'voice' => [
                    'file_id' => $fileId,
                    'file_unique_id' => $fileId.'-unique',
                    'duration' => 3,
                    'mime_type' => 'audio/ogg',
                ],
            ],
        ];
    }

    /** PNG mínimo válido (1x1). */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /** Cabecera Ogg suficiente para fileinfo. */
    private function oggBytes(): string
    {
        return "OggS\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            ."\x01\x1e\x01vorbis\x00\x00\x00\x00\x00\x00\x00\x00";
    }
}
