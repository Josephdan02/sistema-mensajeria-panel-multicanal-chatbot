<?php

namespace App\Services\External\Agenda;

use Illuminate\Support\Str;

class ServiceResolver
{
    public function __construct(private readonly ApiAgendaService $agenda)
    {
    }

    public function resolve(string $service): array
    {
        $query = $this->normalize($service);

        if ($query === '') {
            throw ClinicApiException::unsupported('resolve_service', 'El nombre del servicio es obligatorio.');
        }

        $matches = collect($this->agenda->activeServices())
            ->map(fn (array $item): array => [
                'item' => $item,
                'score' => $this->score($query, $item),
            ])
            ->filter(fn (array $match): bool => $match['score'] > 0)
            ->sortByDesc('score')
            ->values();

        if ($matches->isEmpty()) {
            return ['status' => 'not_found', 'data' => null];
        }

        $bestScore = $matches->first()['score'];
        $best = $matches->where('score', $bestScore)->pluck('item')->values()->all();

        return count($best) === 1
            ? ['status' => 'found', 'data' => $best[0]]
            : ['status' => 'multiple_matches', 'data' => $best];
    }

    private function score(string $query, array $service): int
    {
        $name = $this->normalize((string) $service['nombre']);
        $code = $this->normalize((string) ($service['codigo'] ?? ''));

        if ($query === $name || $query === $code) {
            return 100;
        }

        return str_contains($name, $query) || str_contains($query, $name) ? 50 : 0;
    }

    private function normalize(string $value): string
    {
        $ascii = Str::ascii(Str::lower(Str::squish($value)));

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $ascii));
    }
}
