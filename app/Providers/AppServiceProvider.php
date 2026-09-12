<?php

namespace App\Providers;

use App\Services\External\AI\AIServiceInterface;
use App\Services\External\AI\GeminiAIService;
use App\Services\External\Agenda\AgendaServiceInterface;
use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\MockAgendaService;
use App\Services\External\WhatsApp\MockWhatsAppService;
use App\Services\External\WhatsApp\WhatsAppServiceInterface;
use App\Services\Intent\IntentDetectorInterface;
use App\Services\Intent\RuleBasedIntentDetector;
use App\Services\Slots\RuleBasedServiceExtractor;
use App\Services\Slots\ServiceExtractorInterface;
use App\Services\Slots\DateExtractorInterface;
use App\Services\Slots\RuleBasedDateExtractor;
use App\Services\Slots\TimeExtractorInterface;
use App\Services\Slots\RuleBasedTimeExtractor;
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
        $this->app->bind(WhatsAppServiceInterface::class, MockWhatsAppService::class);
        $this->app->bind(AIServiceInterface::class, GeminiAIService::class);
        $this->app->bind(ServiceExtractorInterface::class, RuleBasedServiceExtractor::class);
        $this->app->bind(DateExtractorInterface::class, RuleBasedDateExtractor::class);
        $this->app->bind(TimeExtractorInterface::class, RuleBasedTimeExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
