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

        if ($field === 'to') {
            return self::matchesRecipient($headers, $value, $operator, null);
        }

        $haystack = match ($field) {
            'from' => $headers['from'] ?? '',
            'from_domain' => self::extractDomain($headers['from'] ?? ''),
            'subject' => $headers['subject'] ?? '',
            'body' => $body ?? '',
            default => '',
        };

        return self::compare($haystack, $value, $operator);
    }

    /**
     * Like matches() but outbound mail is not routed when only the sender's own
     * address satisfied the recipient rule (self-cc / self-bcc on compose).
     *
     * @param array<string, string|null> $headers
     * @param array<string, mixed> $rule
     */
    public static function matchesForDelivery(array $headers, ?string $body, array $rule): bool
    {
        $field = $rule['condition_field'] ?? '';
        if ($field !== 'to') {
            return self::matches($headers, $body, $rule);
        }

        $from = strtolower(trim((string) ($headers['from'] ?? '')));

        return self::matchesRecipient(
            $headers,
            (string) ($rule['condition_value'] ?? ''),
            (string) ($rule['condition_operator'] ?? 'contains'),
            $from !== '' ? $from : null
        );
    }

    /**
     * Recipient rules must be evaluated against each individual address across
     * To / Cc / Bcc / envelope headers (Delivered-To, X-Original-To, etc.).
     * Combining them into one string would break equals/starts_with/ends_with
     * when a mail has multiple recipients.
     *
     * @param array<string, string|null> $headers
     */
    private static function matchesRecipient(
        array $headers,
        string $needle,
        string $operator,
        ?string $excludeEmail = null,
    ): bool {
        $toString = (string) ($headers['to'] ?? '');
        $isEmailNeedle = str_contains($needle, '@');

        // Display-name / partial searches on the visible To/Cc line only.
        if ($operator === 'contains' && !$isEmailNeedle && $toString !== '' && self::compare($toString, $needle, 'contains')) {
            return true;
        }

        foreach (self::recipientAddresses($headers) as $address) {
            if ($excludeEmail !== null && strcasecmp($address, $excludeEmail) === 0) {
                continue;
            }

            if ($isEmailNeedle && $operator === 'contains' && strcasecmp($address, $needle) === 0) {
                return true;
            }

            if (self::compare($address, $needle, $operator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All addressable recipients of the message.
     *
     * The shared mailbox address is dropped from the envelope Delivered-To /
     * X-Original-To headers: in a shared-mailbox setup EVERY message is
     * delivered to that address, so a rule targeting it (e.g. the support
     * alias) would otherwise capture all mail — including mail addressed to a
     * personal alias like ankeshv@. The shared address is still honoured when
     * it appears in the visible To/Cc header (genuine mail to the shared box).
     *
     * Envelope-only copies of the sender's own address are also dropped: when
     * support@ sends to jean@, the MTA often echoes Delivered-To: support@ even
     * though the visible To line is jean@. Without this guard the support folder
     * rule would match and duplicate the outbound copy beside the real delivery.
     *
     * @param array<string, string|null> $headers
     * @return list<string>
     */
    private static function recipientAddresses(array $headers): array
    {
        $shared = strtolower(trim((string) (config('mail')['mailbox_email'] ?? '')));
        $from = strtolower(trim((string) ($headers['from'] ?? '')));

        $visible = self::extractEmails((string) ($headers['to'] ?? ''));
        $visible = array_merge($visible, self::extractEmails((string) ($headers['bcc'] ?? '')));
        $visibleLower = array_map('strtolower', $visible);

        $addresses = $visible;

        $envelopeSources = self::extractEmails((string) ($headers['envelope_recipients'] ?? ''));
        foreach (['delivered_to', 'x_original_to', 'envelope_to'] as $key) {
            $envelopeSources = array_merge(
                $envelopeSources,
                self::extractEmails((string) ($headers[$key] ?? ''))
            );
        }

        foreach ($envelopeSources as $addr) {
            $addrLower = strtolower($addr);
            if ($shared !== '' && $addrLower === $shared) {
                continue;
            }
            if ($from !== '' && $addrLower === $from && !in_array($addrLower, $visibleLower, true)) {
                continue;
            }
            $addresses[] = $addr;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @return list<string>
     */
    private static function extractEmails(string $value): array
    {
        if ($value === '') {
            return [];
        }

        preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $value, $matches);

        return $matches[0] ?? [];
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
