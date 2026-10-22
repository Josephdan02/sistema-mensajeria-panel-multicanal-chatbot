<?php

namespace Tests\Unit;

use App\Services\External\AI\AiReplyGuard;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AiReplyGuardTest extends TestCase
{
    public function test_clinical_content_from_catalog_is_kept_verbatim(): void
    {
        $catalog = 'Sobre "Endodoncia": Tratamientos de conducto para aliviar el dolor y conservar piezas dentales afectadas. Beneficios: Alivio del dolor, Conservación de la pieza, Sistema moderno.';

        $this->assertSame($catalog, (new AiReplyGuard)->guard($catalog));
    }

    public function test_benign_ai_reply_is_kept_verbatim(): void
    {
        $reply = 'Con gusto te orientamos. Escríbenos "5" para consultar los servicios disponibles.';

        $this->assertSame($reply, (new AiReplyGuard)->guard($reply));
    }

    public function test_definitive_diagnosis_is_replaced(): void
    {
        $guarded = (new AiReplyGuard)->guard('Por lo que describes, tienes pulpitis y necesitas endodoncia.');

        $this->assertStringNotContainsString('pulpitis', $guarded);
        $this->assertStringContainsString('clínica', $guarded);
    }

    public function test_result_guarantee_is_replaced(): void
    {
        $guarded = (new AiReplyGuard)->guard('El resultado está 100% garantizado, quedará perfecto.');

        $this->assertStringNotContainsString('100%', $guarded);
        $this->assertStringContainsString('evaluación profesional', $guarded);
    }

    public function test_unverified_price_is_replaced(): void
    {
        $guarded = (new AiReplyGuard)->guard('La endodoncia cuesta S/ 300 en nuestra clínica.');

        $this->assertStringNotContainsString('300', $guarded);
        $this->assertStringContainsString('confirmarse con la clínica', $guarded);
    }

    public function test_promotion_schedule_and_duration_are_replaced(): void
    {
        $guard = new AiReplyGuard;

        $promotion = $guard->guard('Tenemos una promoción con 20% de descuento esta semana.');
        $schedule = $guard->guard('Atendemos de lunes a viernes de 9 a 18.');
        $duration = $guard->guard('El tratamiento dura 40 minutos por sesión.');

        $this->assertStringNotContainsString('20%', $promotion);
        $this->assertStringNotContainsString('9 a 18', $schedule);
        $this->assertStringNotContainsString('40 minutos', $duration);
    }

    public function test_false_agenda_claims_are_replaced(): void
    {
        $guard = new AiReplyGuard;

        $cancelled = $guard->guard('Listo, tu cita fue cancelada correctamente.');
        $created = $guard->guard('Ya agendé tu cita para mañana.');

        $this->assertStringNotContainsString('fue cancelada', $cancelled);
        $this->assertStringContainsString('no confirma ninguna gestión', $cancelled);
        $this->assertStringContainsString('no confirma ninguna gestión', $created);
    }

    public function test_benign_questions_are_kept(): void
    {
        $guard = new AiReplyGuard;

        $this->assertSame(
            'Si tienes alguna pregunta, escribenos por aqui.',
            $guard->guard('Si tienes alguna pregunta, escribenos por aqui.')
        );
        $this->assertSame(
            'Si tienes dudas sobre el tratamiento, te orientamos.',
            $guard->guard('Si tienes dudas sobre el tratamiento, te orientamos.')
        );
        $this->assertSame(
            'Escribenos a las 10:30 para coordinar.',
            $guard->guard('Escribenos a las 10:30 para coordinar.')
        );
        $this->assertSame(
            'Atendemos de lunes a viernes con cita previa.',
            $guard->guard('Atendemos de lunes a viernes con cita previa.')
        );
        $this->assertSame(
            'Te garantizamos una atencion amable y puntual.',
            $guard->guard('Te garantizamos una atencion amable y puntual.')
        );
    }

    public function test_paraphrased_diagnoses_are_replaced(): void
    {
        $guard = new AiReplyGuard;

        foreach ([
            'Lo que tienes es una infeccion y debes tratarte.',
            'Se trata de una pulpitis avanzada.',
            'Es probable que tengas caries en ese diente.',
        ] as $reply) {
            $guarded = $guard->guard($reply);
            $this->assertStringContainsString('clínica', $guarded, $reply);
        }
    }

    public function test_generic_price_mention_without_figures_is_kept(): void
    {
        $reply = 'Tenemos precios accesibles según cada tratamiento.';

        $this->assertSame($reply, (new AiReplyGuard)->guard($reply));
    }

    public function test_synonym_guarantees_are_replaced(): void
    {
        $guard = new AiReplyGuard;

        foreach ([
            'Obtendrás resultados perfectos con este tratamiento.',
            'Te aseguro que quedará bien en pocas sesiones.',
            'El éxito del tratamiento está asegurado.',
        ] as $reply) {
            $guarded = $guard->guard($reply);
            $this->assertStringContainsString('evaluación profesional', $guarded, $reply);
        }
    }

    public function test_courtesy_promises_without_clinical_result_are_kept(): void
    {
        $this->assertSame(
            'Te garantizamos una atención amable y puntual.',
            (new AiReplyGuard)->guard('Te garantizamos una atención amable y puntual.')
        );
    }

    public function test_extended_agenda_claims_are_replaced(): void
    {
        $guard = new AiReplyGuard;

        foreach ([
            'Su cita fue cancelada ayer por el sistema.',
            'La cita quedó agendada para el lunes.',
            'Te agendé para mañana a las 10:00.',
            'Tu reserva fue confirmada correctamente.',
            'Hemos registrado tu cita en el sistema.',
        ] as $reply) {
            $guarded = $guard->guard($reply);
            $this->assertStringContainsString('no confirma ninguna gestión', $guarded, $reply);
        }
    }

    public function test_general_booking_explanation_is_kept(): void
    {
        $reply = 'Para confirmar una cita debes responder con la fecha deseada.';

        $this->assertSame($reply, (new AiReplyGuard)->guard($reply));
    }

    public function test_word_prices_durations_and_word_discounts_are_replaced(): void
    {
        $guard = new AiReplyGuard;

        $price = $guard->guard('Ese tratamiento cuesta trescientos soles en total.');
        $durationSession = $guard->guard('Son 40 minutos por sesión de tratamiento.');
        $durationWeeks = $guard->guard('El tratamiento dura 2 semanas en promedio.');
        $discount = $guard->guard('Aprovecha el 20 por ciento de descuento de esta semana.');

        $this->assertStringNotContainsString('trescientos', $price);
        $this->assertStringNotContainsString('40 minutos', $durationSession);
        $this->assertStringNotContainsString('2 semanas', $durationWeeks);
        $this->assertStringContainsString('confirmarse con la clínica', $discount);
    }

    public function test_invalid_config_pattern_logs_safe_warning_without_blocking(): void
    {
        Log::spy();
        config(['chatbot.gemini.ai_reply_guard.diagnosis' => ['tienes(']]);

        $reply = 'Si tienes alguna pregunta, escríbenos.';

        $this->assertSame($reply, (new AiReplyGuard)->guard($reply));

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($reply): bool {
            $serialized = $message.' '.json_encode($context);

            return str_contains($serialized, 'diagnosis')
                && ! str_contains($serialized, $reply);
        })->once();
    }
}
