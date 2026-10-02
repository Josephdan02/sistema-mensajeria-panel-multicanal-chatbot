<?php

namespace App\Services\Slots;

class RuleBasedDateExtractor implements DateExtractorInterface
{
    public function extract(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));

        if (preg_match('/\b(?:este|pr[oó]ximo|pr[oó]xima|que\s+viene|semana)\b/u', $normalized) === 1) {
            return null;
        }

        foreach ([
            'pasado mañana' => 2,
            'pasado manana' => 2,
            'mañana' => 1,
            'manana' => 1,
            'hoy' => 0,
        ] as $relativeDate => $daysToAdd) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($relativeDate, '/').'(?!(?:[\p{L}\p{N}]))/u', $normalized) === 1) {
                return $this->relativeDate($daysToAdd);
            }
        }

        $weekdays = [
            'domingo' => 0,
            'lunes' => 1,
            'martes' => 2,
            'miércoles' => 3,
            'miercoles' => 3,
            'jueves' => 4,
            'viernes' => 5,
            'sábado' => 6,
            'sabado' => 6,
        ];

        foreach ($weekdays as $weekday => $weekdayNumber) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($weekday, '/').'(?!(?:[\p{L}\p{N}]))/u', $normalized) === 1) {
                return $this->nextWeekday($weekdayNumber);
            }
        }

        preg_match_all('/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/', $message, $matches);

        if (count($matches[0]) === 1) {
            return $matches[0][0];
        }

        preg_match_all('/(?<!\d)(\d{1,2})\/(\d{1,2})\/(\d{4})(?!\d)/', $message, $slashMatches);

        if (count($slashMatches[0]) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $slashMatches[3][0], (int) $slashMatches[2][0], (int) $slashMatches[1][0]);
        }

        return null;
    }

    private function relativeDate(int $daysToAdd): string
    {
        $timezone = function_exists('app') && app()->bound('config')
            ? (string) config('app.timezone', date_default_timezone_get())
            : date_default_timezone_get();

        return (new \DateTimeImmutable('today', new \DateTimeZone($timezone)))
            ->modify("+{$daysToAdd} days")
            ->format('Y-m-d');
    }

    private function nextWeekday(int $weekdayNumber): string
    {
        $timezone = function_exists('app') && app()->bound('config')
            ? (string) config('app.timezone', date_default_timezone_get())
            : date_default_timezone_get();
        $today = new \DateTimeImmutable('today', new \DateTimeZone($timezone));
        $daysAhead = ($weekdayNumber - (int) $today->format('w') + 7) % 7;

        return $today->modify('+'.($daysAhead === 0 ? 7 : $daysAhead).' days')->format('Y-m-d');
    }
}
