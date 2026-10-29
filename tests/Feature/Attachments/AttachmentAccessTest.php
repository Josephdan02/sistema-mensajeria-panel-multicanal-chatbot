<?php

namespace Tests\Feature\Attachments;

use App\Enums\AttachmentStatus;
use App\Models\User;
use App\Services\Attachments\LocalAttachmentStorage;
use App\Services\Attachments\SupabaseAttachmentStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Acceso del agente a los adjuntos: descarga autorizada, previsualización y
 * denegación a usuarios sin permisos.
 */
class AttachmentAccessTest extends AttachmentTestCase
{
    public function test_agent_can_download_an_attachment_of_an_assigned_conversation(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $attachment = $this->attachment($conversation);
        $this->storedContent = 'PDF de prueba';

        $response = $this->actingAs($agent)
            ->get(route('agent.attachments.download', $attachment));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('PDF de prueba', $response->getContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_agent_can_preview_an_image_inline(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $attachment = $this->attachment($conversation, [
            'kind' => 'image',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'original_name' => 'radiografia.jpg',
        ]);

        $response = $this->actingAs($agent)
            ->get(route('agent.attachments.download', $attachment).'?inline=1');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_agent_can_open_the_attachment_detail_view(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $attachment = $this->attachment($conversation);

        $this->actingAs($agent)
            ->get(route('agent.attachments.show', $attachment))
            ->assertOk()
            ->assertSee('informe.pdf');
    }

    public function test_agent_cannot_access_an_attachment_of_another_agents_conversation(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $other->id]);
        $attachment = $this->attachment($conversation);

        $this->actingAs($agent)
            ->get(route('agent.attachments.download', $attachment))
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('agent.attachments.show', $attachment))
            ->assertForbidden();
    }

    public function test_supervisor_can_access_any_attachment(): void
    {
        $supervisor = $this->supervisor();
        $other = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $other->id]);
        $attachment = $this->attachment($conversation);

        $this->actingAs($supervisor)
            ->get(route('agent.attachments.download', $attachment))
            ->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $conversation = $this->conversation();
        $attachment = $this->attachment($conversation);

        $this->get(route('agent.attachments.download', $attachment))
            ->assertRedirect(route('agent.login'));
    }

    public function test_user_without_agent_role_is_logged_out_and_redirected(): void
    {
        $user = User::factory()->create(['role' => null, 'active' => true]);
        $conversation = $this->conversation();
        $attachment = $this->attachment($conversation);

        $this->actingAs($user)
            ->get(route('agent.attachments.download', $attachment))
            ->assertRedirect(route('agent.login'));
    }

    public function test_unavailable_attachment_returns_not_found(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $attachment = $this->attachment($conversation, [
            'status' => AttachmentStatus::UNAVAILABLE->value,
            'storage_path' => null,
        ]);

        $this->actingAs($agent)
            ->get(route('agent.attachments.download', $attachment))
            ->assertNotFound();
    }

    public function test_storage_failure_returns_not_found_without_leaking_secrets(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $attachment = $this->attachment($conversation);
        $this->storageFails = true;

        $response = $this->actingAs($agent)
            ->get(route('agent.attachments.download', $attachment));

        $response->assertNotFound();
        $this->assertStringNotContainsString('service_role', $response->getContent());
    }

    public function test_download_does_not_expose_storage_path_or_bot_token(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $attachment = $this->attachment($conversation);

        $response = $this->actingAs($agent)
            ->get(route('agent.attachments.download', $attachment));

        $response->assertOk();
        $this->assertStringNotContainsString('telegram/document/2026/10', $response->getContent());
        $this->assertStringNotContainsString('bottest-bot-token', $response->getContent());
    }

    public function test_thread_renders_the_attachment_controls(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $message = $this->customerMessage($conversation, 'El cliente envió una foto por Telegram.');
        $attachment = $this->attachment($conversation, [
            'message_id' => $message->id,
            'kind' => 'image',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'original_name' => 'radiografia.jpg',
            'size' => 2048,
        ]);

        $this->actingAs($agent)
            ->get(route('agent.conversations.show', $conversation))
            ->assertOk()
            ->assertSee('radiografia.jpg')
            ->assertSee(route('agent.attachments.download', $attachment));
    }

    public function test_thread_shows_a_readable_error_for_an_unavailable_attachment(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $message = $this->customerMessage($conversation, 'El cliente envió un documento por Telegram.');
        $this->attachment($conversation, [
            'message_id' => $message->id,
            'status' => AttachmentStatus::TOO_LARGE->value,
            'storage_path' => null,
            'error_message' => 'El archivo supera el tamaño máximo permitido.',
        ]);

        $this->actingAs($agent)
            ->get(route('agent.conversations.show', $conversation))
            ->assertOk()
            ->assertSee('Adjunto no disponible')
            ->assertSee('El archivo supera el tamaño máximo permitido.');
    }

    public function test_download_rejects_an_unsafe_storage_path(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['assigned_agent_id' => $agent->id]);
        $attachment = $this->attachment($conversation, [
            'storage_path' => '../../secretos/archivo.pdf',
        ]);

        $this->actingAs($agent)
            ->get(route('agent.attachments.download', $attachment))
            ->assertNotFound();
    }

    public function test_local_driver_stores_the_file_in_a_private_disk(): void
    {
        config()->set('attachments.driver', 'local');
        Storage::fake('local');

        $storage = new LocalAttachmentStorage;
        $result = $storage->put('telegram/image/2026/10/archivo.jpg', 'contenido', 'image/jpeg');

        $this->assertTrue($result->stored);
        $this->assertSame('telegram/image/2026/10/archivo.jpg', $result->path);
        Storage::disk('local')->assertExists('telegram/image/2026/10/archivo.jpg');
    }

    public function test_supabase_storage_never_exposes_the_service_role_key(): void
    {
        config()->set('services.supabase.url', 'https://proyecto.supabase.co');
        config()->set('services.supabase.service_role_key', 'clave-secreta-de-prueba');
        config()->set('services.supabase.bucket', 'chatbot-attachments');

        Http::fake([
            'proyecto.supabase.co/*' => Http::response('ok', 200),
        ]);

        $storage = new SupabaseAttachmentStorage;
        $result = $storage->put('telegram/document/2026/10/archivo.pdf', 'contenido', 'application/pdf');

        $this->assertTrue($result->stored);
        $this->assertSame('telegram/document/2026/10/archivo.pdf', $result->path);

        Http::assertSent(function ($request): bool {
            $this->assertStringContainsString(
                '/storage/v1/object/chatbot-attachments/',
                $request->url(),
            );
            $this->assertStringNotContainsString('clave-secreta-de-prueba', $request->url());

            return true;
        });
    }

    public function test_supabase_storage_fails_gracefully_without_credentials(): void
    {
        config()->set('services.supabase.url', null);
        config()->set('services.supabase.service_role_key', null);

        $storage = new SupabaseAttachmentStorage;

        $this->assertFalse($storage->isAvailable());
        $result = $storage->put('telegram/document/2026/10/archivo.pdf', 'contenido', 'application/pdf');
        $this->assertFalse($result->stored);
        $this->assertNull($result->path);
    }
}
