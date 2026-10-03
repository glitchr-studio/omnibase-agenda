<?php

namespace Base\Agenda\Service;

use Base\Agenda\Model\ParsedEvent;

/**
 * Another site's /agenda.json (Service\Feed wrote it) read back into
 * ParsedEvents: the dates of a sister site, an ensemble's, an agency's
 * that runs omnibase/agenda too. Everything the feed says is taken - the
 * uid is the feed's `id`, so a date read from two places is one. No HTTP
 * here: Service\IcsImporter follows `next`.
 */
final class FeedParser
{
    /** Whether a decoded JSON is one of our feeds. */
    public static function supports(mixed $data): bool
    {
        return \is_array($data) && \array_key_exists('events', $data) && \array_key_exists('calendar', $data);
    }

    /** The feed's next page, absolute already; null on the last one. */
    public static function next(array $data): ?string
    {
        return \is_string($data['next'] ?? null) && preg_match('#^https?://#i', $data['next']) ? $data['next'] : null;
    }

    /**
     * @param array<string, mixed>|string $json
     *
     * @return list<ParsedEvent>
     */
    public function parse(array|string $json): array
    {
        $data = \is_string($json) ? json_decode($json, true, 512, \JSON_THROW_ON_ERROR) : $json;
        $events = [];
        foreach ($data['events'] ?? [] as $item) {
            if (\is_array($item) && $event = $this->event($item)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $item */
    private function event(array $item): ?ParsedEvent
    {
        $uid = trim((string) ($item['id'] ?? ''));
        $timezone = self::zone((string) ($item['timezone'] ?? '')) ?? 'UTC';
        $start = self::moment($item['start'] ?? null, $timezone);
        if ('' === $uid || !$start) {
            return null;
        }
        $end = self::moment($item['end'] ?? null, $timezone);
        $venue = \is_array($item['venue'] ?? null) ? $item['venue'] : null;
        $text = fn (string $key): ?string => \is_string($item[$key] ?? null) && '' !== trim($item[$key]) ? trim($item[$key]) : null;
        $list = fn (string $key): ?array => \is_array($item[$key] ?? null) ? array_values(array_filter(array_map('strval', $item[$key]), fn (string $line) => '' !== trim($line))) : null;

        return new ParsedEvent(
            uid: $uid,
            summary: $text('title') ?? '—',
            start: $start,
            end: $end && $end > $start ? $end : null,
            allDay: (bool) ($item['allDay'] ?? false),
            timezone: $timezone,
            location: $venue ? (implode(', ', array_filter([$venue['name'] ?? null, $venue['hall'] ?? null, $venue['address'] ?? null, trim(($venue['postcode'] ?? '').' '.($venue['city'] ?? '')), $venue['country'] ?? null])) ?: null) : null,
            // The organiser's page, else the date's page on the site it comes from.
            url: $text('eventUrl') ?? $text('url'),
            lastModified: self::moment($item['updatedAt'] ?? null, 'UTC'),
            cancelled: (bool) ($item['cancelled'] ?? false),
            ensemble: $text('ensemble'),
            conductor: $text('conductor'),
            role: $text('role'),
            performers: $list('performers'),
            programme: $list('programme'),
            tickets: $text('tickets'),
            image: $text('image'),
            place: $venue && \is_string($venue['name'] ?? null) && '' !== trim($venue['name']) ? [
                'name' => trim($venue['name']),
                'hall' => $venue['hall'] ?? null,
                'address' => $venue['address'] ?? null,
                'postcode' => isset($venue['postcode']) ? (string) $venue['postcode'] : null,
                'city' => $venue['city'] ?? null,
                'country' => $venue['country'] ?? null,
                'latitude' => is_numeric($venue['latitude'] ?? null) ? (float) $venue['latitude'] : null,
                'longitude' => is_numeric($venue['longitude'] ?? null) ? (float) $venue['longitude'] : null,
            ] : null,
        );
    }

    private static function moment(mixed $value, string $timezone): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone($timezone));
        } catch (\Exception) {
            return null;
        }
    }

    private static function zone(string $name): ?string
    {
        try {
            return '' !== $name ? (new \DateTimeZone($name))->getName() : null;
        } catch (\Exception) {
            return null;
        }
    }
}
