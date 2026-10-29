<?php

namespace App\Providers;

use App\Services\Attachments\AttachmentStorageInterface;
use App\Services\Attachments\LocalAttachmentStorage;
use App\Services\Attachments\SupabaseAttachmentStorage;
use App\Services\Chatbot\ChatbotService;
use App\Services\External\Agenda\AgendaServiceInterface;
use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\MockAgendaService;
use App\Services\External\AI\AiReplyGuard;
use App\Services\External\AI\AIServiceInterface;
use App\Services\External\AI\GeminiAIService;
use App\Services\External\Telegram\MockTelegramService;
use App\Services\External\Telegram\TelegramApiService;
use App\Services\External\Telegram\TelegramServiceInterface;
use App\Services\External\WhatsApp\CloudApiWhatsAppService;
use App\Services\External\WhatsApp\MockWhatsAppService;
use App\Services\External\WhatsApp\WhatsAppServiceInterface;
use App\Services\Intent\IntentDetectorInterface;
use App\Services\Intent\RuleBasedIntentDetector;
use App\Services\Slots\DateExtractorInterface;
use App\Services\Slots\RuleBasedDateExtractor;
use App\Services\Slots\RuleBasedServiceExtractor;
use App\Services\Slots\RuleBasedTimeExtractor;
use App\Services\Slots\ServiceExtractorInterface;
use App\Services\Slots\TimeExtractorInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(IntentDetectorInterface::class, RuleBasedIntentDetector::class);
        $agendaService = strtolower(trim((string) config('services.clinic_agenda.driver', 'mock')));
        $agendaImplementation = $agendaService === 'api'
            ? ApiAgendaService::class
            : MockAgendaService::class;

        $this->app->singleton(AgendaServiceInterface::class, $agendaImplementation);

        // WhatsApp: 'mock' solo loguea (desarrollo/pruebas); 'api' usa
        // CloudApiWhatsAppService contra graph.facebook.com. Mismo patrón
        // condicional que AGENDA_SERVICE.
        $whatsappDriver = strtolower(trim((string) config('services.whatsapp.driver', 'mock')));
        $whatsappImplementation = $whatsappDriver === 'api'
            ? CloudApiWhatsAppService::class
            : MockWhatsAppService::class;

        $this->app->bind(WhatsAppServiceInterface::class, $whatsappImplementation);

        $telegramDriver = strtolower(trim((string) config('services.telegram.driver', 'api')));
        $telegramImplementation = $telegramDriver === 'api'
            ? TelegramApiService::class
            : MockTelegramService::class;

        $this->app->bind(TelegramServiceInterface::class, $telegramImplementation);
        $this->app->bind(AIServiceInterface::class, GeminiAIService::class);
        $this->app->bind(ServiceExtractorInterface::class, RuleBasedServiceExtractor::class);
        $this->app->bind(DateExtractorInterface::class, RuleBasedDateExtractor::class);
        $this->app->bind(TimeExtractorInterface::class, RuleBasedTimeExtractor::class);

        // Adjuntos: Supabase Storage (bucket privado) por defecto. El disco
        // local `local` (storage/app/private) queda como respaldo para entornos
        // sin credenciales configuradas.
        $attachmentDriver = strtolower(trim((string) config('attachments.driver', 'supabase')));
        $supabaseStorageConfigured = trim((string) config('services.supabase.url', '')) !== ''
            && trim((string) config('services.supabase.service_role_key', '')) !== '';

        $this->app->singleton(AttachmentStorageInterface::class, function () use ($attachmentDriver, $supabaseStorageConfigured) {
            if ($attachmentDriver === 'local' || ! $supabaseStorageConfigured) {
                return new LocalAttachmentStorage;
            }

            return new SupabaseAttachmentStorage;
        });
        $this->app->bind(AiReplyGuard::class);
        $this->app->when(ChatbotService::class)
            ->needs(AiReplyGuard::class)
            ->give(fn () => $this->app->make(AiReplyGuard::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Request $request): void
    {
        $forwardedProto = strtolower(trim((string) $request->headers->get('X-Forwarded-Proto')));
        $forwardedHost = trim((string) $request->headers->get('X-Forwarded-Host'));

        if (
            $forwardedProto === 'https'
            && preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $forwardedHost) === 1
        ) {
            URL::forceRootUrl('https://'.$forwardedHost);
            URL::forceScheme('https');
        }
    }
}
