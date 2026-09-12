<?php

namespace App\Services\External\Agenda;

use Illuminate\Support\Str;

class ProfessionalResolver
{
    public function __construct(private readonly ApiAgendaService $agenda)
    {
    }

    public function resolve(string $professional): array
    {
        $query = $this->normalize($professional);

        if ($query === '') {
            throw ClinicApiException::unsupported('resolve_professional', 'El nombre del profesional es obligatorio.');
        }

        $professionals = $this->agenda->activeProfessionals();
        $exact = array_values(array_filter($professionals, fn (array $item): bool => $query === $this->fullName($item)));
        $matches = $exact !== [] ? $exact : array_values(array_filter(
            $professionals,
            fn (array $item): bool => $this->containsAllTokens($this->fullName($item), $query),
        ));

        return match (count($matches)) {
            0 => ['status' => 'not_found', 'data' => null],
            1 => ['status' => 'found', 'data' => $matches[0]],
            default => ['status' => 'multiple_matches', 'data' => $matches],
        };
    }

    private function fullName(array $professional): string
    {
        return $this->normalize($professional['nombres'].' '.$professional['apellidos']);
    }

    private function containsAllTokens(string $value, string $query): bool
    {
        foreach (explode(' ', $query) as $token) {
            if ($token !== '' && ! str_contains($value, $token)) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $value): string
    {
        $ascii = Str::ascii(Str::lower(Str::squish($value)));
        $ascii = preg_replace('/\b(?:dr|dra|doctor|doctora)\b\.?/u', ' ', $ascii) ?? $ascii;

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $ascii));
    }
}
