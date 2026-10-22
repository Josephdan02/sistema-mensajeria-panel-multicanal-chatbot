<?php

namespace App\Services\Slots;

use Illuminate\Support\Str;

class RuleBasedServiceExtractor implements ServiceExtractorInterface
{
    /**
     * @param array<string, array{name: string, aliases: array<int, string>, description?: string, benefits?: array<int, string>}>|null $catalog
     */
    public function __construct(?array $catalog = null)
    {
        $this->catalog = $catalog ?? config('chatbot.services', []);
    }

    /** @var array<string, array{name: string, aliases: array<int, string>, description?: string, benefits?: array<int, string>}> */
    private array $catalog;

    public function extract(string $message): ?string
    {
        $normalizedMessage = $this->normalize($message);
        $matches = [];

        foreach ($this->catalog as $service) {
            foreach ($service['aliases'] as $alias) {
                $normalizedAlias = $this->normalize($alias);
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($normalizedAlias, '/').'(?:(?![\p{L}\p{N}])|$)/u';

                if (preg_match($pattern, $normalizedMessage) === 1) {
                    $matches[$service['name']] = $matches[$service['name']] ?? $normalizedAlias;

                    if (mb_strlen($normalizedAlias) > mb_strlen($matches[$service['name']])) {
                        $matches[$service['name']] = $normalizedAlias;
                    }

                    break;
                }
            }
        }

        foreach ($matches as $serviceName => $matchedAlias) {
            foreach ($matches as $otherServiceName => $otherAlias) {
                if ($serviceName !== $otherServiceName
                    && mb_strlen($otherAlias) > mb_strlen($matchedAlias)
                    && str_contains($otherAlias, $matchedAlias)) {
                    unset($matches[$serviceName]);
                    break;
                }
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    private function normalize(string $text): string
    {
        $normalized = Str::ascii(Str::lower(Str::squish($text)));

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }
}
