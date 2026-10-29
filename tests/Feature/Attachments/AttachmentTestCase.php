<?php

namespace Tests\Feature\Attachments;

use App\Enums\AttachmentStatus;
use App\Enums\ConversationState;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageInterface;
use App\Services\Attachments\AttachmentStorageResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base común de las pruebas de adjuntos: almacenamiento en memoria (nunca
 * Supabase ni Telegram reales) y usuarios de prueba.
 */
abstract class AttachmentTestCase extends TestCase
{
    use RefreshDatabase;

    /** Contenido que devuelve el almacenamiento en memoria. */
    public string $storedContent = 'contenido';

    /** Si el almacenamiento simula un fallo. */
    public bool $storageFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AttachmentStorageInterface::class, new class($this) implements AttachmentStorageInterface
        {
            public function __construct(private readonly AttachmentTestCase $test) {}

            public function put(string $path, string $contents, string $mimeType): AttachmentStorageResult
            {
                if ($this->test->storageFails) {
                    return AttachmentStorageResult::failed('Error simulado de almacenamiento.');
                }

                $this->test->storedContent = $contents;

                return AttachmentStorageResult::stored($path);
            }

            public function get(string $path): ?string
            {
                if ($this->test->storageFails) {
                    return null;
                }

                return $this->test->storedContent;
            }

            public function delete(string $path): bool
            {
                return true;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        });
    }

    protected function agent(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Agente de Prueba',
            'email' => 'agente'.uniqid().'@cabanillas.test',
            'password' => 'agente1234',
            'role' => User::ROLE_AGENT,
            'active' => true,
        ], $attributes));
    }

    protected function supervisor(array $attributes = []): User
    {
        return $this->agent(array_merge([
            'name' => 'Supervisor de Prueba',
            'role' => User::ROLE_SUPERVISOR,
        ], $attributes));
    }

    /**
     * Conversación de prueba. Por defecto usa el mismo identificador que usan
     * los webhooks de los tests de ingesta (`12389`) para que el webhook
     * resuelva esta misma conversación.
     */
    protected function conversation(array $attributes = []): Conversation
    {
        return Conversation::create(array_merge([
            'user_identifier' => '12389',
            'channel' => 'telegram',
            'state' => ConversationState::WAITING_AGENT,
        ], $attributes));
    }

    protected function attachment(Conversation $conversation, array $attributes = []): Attachment
    {
        return Attachment::create(array_merge([
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
            'telegram_file_id' => 'file-'.uniqid(),
            'kind' => 'document',
            'original_name' => 'informe.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => AttachmentStatus::AVAILABLE->value,
            'storage_path' => 'telegram/document/2026/10/archivo.pdf',
        ], $attributes));
    }

    /** Crea un mensaje del cliente en la conversación. */
    protected function customerMessage(Conversation $conversation, string $text = 'Hola'): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => $text,
            'intent' => null,
        ]);
    }
}
