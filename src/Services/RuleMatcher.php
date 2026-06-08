<?php

declare(strict_types=1);

namespace App\Services;

class RuleMatcher
{
    /**
     * @param array<string, string|null> $headers
     * @param array<string, mixed> $rule
     */
    public static function matches(array $headers, ?string $body, array $rule): bool
    {
        $field = $rule['condition_field'] ?? '';
        $operator = $rule['condition_operator'] ?? 'contains';
        $value = $rule['condition_value'] ?? '';

        $haystack = match ($field) {
            'to' => self::combineToFields($headers),
            'from' => $headers['from'] ?? '',
            'from_domain' => self::extractDomain($headers['from'] ?? ''),
            'subject' => $headers['subject'] ?? '',
            'body' => $body ?? '',
            default => '',
        };

        return self::compare($haystack, $value, $operator);
    }

    /**
     * @param array<string, string|null> $headers
     */
    private static function combineToFields(array $headers): string
    {
        $parts = array_filter([
            $headers['to'] ?? null,
            $headers['delivered_to'] ?? null,
            $headers['x_original_to'] ?? null,
        ]);

        return implode(' ', $parts);
    }

    private static function extractDomain(string $from): string
    {
        if (preg_match('/@([a-zA-Z0-9.-]+)/', $from, $m)) {
            return $m[1];
        }

        return $from;
    }

    private static function compare(string $haystack, string $needle, string $operator): bool
    {
        $haystack = strtolower($haystack);
        $needle = strtolower($needle);

        return match ($operator) {
            'equals' => $haystack === $needle,
            'contains' => str_contains($haystack, $needle),
            'starts_with' => str_starts_with($haystack, $needle),
            'ends_with' => str_ends_with($haystack, $needle),
            default => false,
        };
    }
}
