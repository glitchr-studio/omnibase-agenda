<?php

namespace Base\Agenda\Service;

use Base\Agenda\Model\ParsedEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The dates of a Squarespace events page, read from its JSON
 * (`/performances?format=json`): what an artist's former site holds while
 * the new one takes over. Each entry is a ParsedEvent (uid
 * "squarespace:<id>"); its HTML body is read the way such pages are typed -
 * a first line in bold, the orchestra; "**Name** Conductor", "**Name**
 * Piano", the performers; "**Composer** Work", the programme.
 *
 * Squarespace keeps every hour as the site's own clock, Berlin's, whatever
 * the hall: the hour typed is read on that clock and put back in the
 * hall's timezone, the country's. No HTTP here: Service\IcsImporter
 * fetches the pages (nextPage() says which comes next).
 */
final class SquarespaceParser
{
    /** The clock Squarespace stored the hours on. */
    public const SITE_TIMEZONE = 'Europe/Berlin';

    /** ISO country → its timezone (the capital's: one per country is what a concert hall needs). */
    public const TIMEZONES = [
        'DE' => 'Europe/Berlin', 'AT' => 'Europe/Vienna', 'CH' => 'Europe/Zurich', 'FR' => 'Europe/Paris', 'IT' => 'Europe/Rome',
        'NL' => 'Europe/Amsterdam', 'BE' => 'Europe/Brussels', 'LU' => 'Europe/Luxembourg', 'HU' => 'Europe/Budapest', 'PL' => 'Europe/Warsaw',
        'SK' => 'Europe/Bratislava', 'CZ' => 'Europe/Prague', 'NO' => 'Europe/Oslo', 'DK' => 'Europe/Copenhagen', 'SE' => 'Europe/Stockholm',
        'FI' => 'Europe/Helsinki', 'ES' => 'Europe/Madrid', 'PT' => 'Europe/Lisbon', 'GB' => 'Europe/London', 'IE' => 'Europe/Dublin',
        'EE' => 'Europe/Tallinn', 'LV' => 'Europe/Riga', 'LT' => 'Europe/Vilnius', 'GR' => 'Europe/Athens', 'SI' => 'Europe/Ljubljana', 'HR' => 'Europe/Zagreb',
        'TW' => 'Asia/Taipei', 'CN' => 'Asia/Shanghai', 'KR' => 'Asia/Seoul', 'JP' => 'Asia/Tokyo', 'AE' => 'Asia/Dubai', 'IL' => 'Asia/Jerusalem',
        'BR' => 'America/Sao_Paulo', 'US' => 'America/New_York', 'CA' => 'America/Toronto', 'AU' => 'Australia/Sydney',
    ];

    /** Country names as Squarespace writes them (in the site's language, French here) and in English and German, folded. */
    public const COUNTRIES = [
        'allemagne' => 'DE', 'germany' => 'DE', 'deutschland' => 'DE', 'autriche' => 'AT', 'austria' => 'AT', 'osterreich' => 'AT',
        'suisse' => 'CH', 'switzerland' => 'CH', 'schweiz' => 'CH', 'france' => 'FR', 'frankreich' => 'FR', 'italie' => 'IT', 'italy' => 'IT', 'italien' => 'IT', 'italia' => 'IT',
        'pays-bas' => 'NL', 'netherlands' => 'NL', 'niederlande' => 'NL', 'belgique' => 'BE', 'belgium' => 'BE', 'belgien' => 'BE', 'luxembourg' => 'LU', 'luxemburg' => 'LU',
        'hongrie' => 'HU', 'hungary' => 'HU', 'ungarn' => 'HU', 'pologne' => 'PL', 'poland' => 'PL', 'polen' => 'PL',
        'slovaquie' => 'SK', 'slovakia' => 'SK', 'slowakei' => 'SK', 'tchequie' => 'CZ', 'republique tcheque' => 'CZ', 'czech republic' => 'CZ', 'czechia' => 'CZ', 'tschechien' => 'CZ',
        'norvege' => 'NO', 'norway' => 'NO', 'norwegen' => 'NO', 'danemark' => 'DK', 'denmark' => 'DK', 'suede' => 'SE', 'sweden' => 'SE', 'schweden' => 'SE',
        'finlande' => 'FI', 'finland' => 'FI', 'finnland' => 'FI', 'espagne' => 'ES', 'spain' => 'ES', 'spanien' => 'ES', 'portugal' => 'PT',
        'royaume-uni' => 'GB', 'united kingdom' => 'GB', 'uk' => 'GB', 'england' => 'GB', 'angleterre' => 'GB', 'grossbritannien' => 'GB', 'irlande' => 'IE', 'ireland' => 'IE',
        'estonie' => 'EE', 'estonia' => 'EE', 'estland' => 'EE', 'lettonie' => 'LV', 'latvia' => 'LV', 'lituanie' => 'LT', 'lithuania' => 'LT',
        'grece' => 'GR', 'greece' => 'GR', 'griechenland' => 'GR', 'slovenie' => 'SI', 'slovenia' => 'SI', 'croatie' => 'HR', 'croatia' => 'HR',
        'taiwan' => 'TW', 'chine' => 'CN', 'china' => 'CN', 'coree du sud' => 'KR', 'south korea' => 'KR', 'korea' => 'KR', 'republic of korea' => 'KR', 'sudkorea' => 'KR',
        'japon' => 'JP', 'japan' => 'JP', 'emirats arabes unis' => 'AE', 'united arab emirates' => 'AE', 'uae' => 'AE', 'israel' => 'IL',
        'bresil' => 'BR', 'brazil' => 'BR', 'brasilien' => 'BR', 'etats-unis' => 'US', 'united states' => 'US', 'usa' => 'US', 'canada' => 'CA', 'australie' => 'AU', 'australia' => 'AU',
    ];

    /** The default map position Squarespace gives an address it did not place (New York): no coordinates. */
    private const UNPLACED = [40.7207559, -74.0007613];

    public function __construct(#[Autowire('%agenda.timezone%')] private readonly string $defaultTimezone = 'Europe/Berlin')
    {
    }

    /** Whether a decoded JSON is a Squarespace events collection. */
    public static function supports(mixed $data): bool
    {
        return \is_array($data) && (\array_key_exists('upcoming', $data) || \array_key_exists('past', $data));
    }

    /**
     * The JSON address of an events page: "?format=json" added when missing.
     */
    public static function jsonUrl(string $url): string
    {
        if (preg_match('/[?&]format=json\b/', $url)) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'format=json';
    }

    /**
     * The next page of the past dates (pagination.nextPageUrl), as an
     * absolute JSON address on the host of $address; null on the last page.
     */
    public static function nextPage(array $data, string $address): ?string
    {
        $next = $data['pagination']['nextPageUrl'] ?? null;
        if (!\is_string($next) || '' === $next || empty($data['pagination']['nextPage'] ?? true)) {
            return null;
        }
        if (!preg_match('#^https?://#i', $next)) {
            $parts = parse_url($address);
            $next = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').'/'.ltrim($next, '/');
        }

        return self::jsonUrl($next);
    }

    /**
     * The upcoming dates and the past ones of a page.
     *
     * @param array<string, mixed>|string $json
     *
     * @return list<ParsedEvent>
     */
    public function parse(array|string $json): array
    {
        $data = \is_string($json) ? json_decode($json, true, 512, \JSON_THROW_ON_ERROR) : $json;
        $events = [];
        foreach (['upcoming', 'past'] as $list) {
            foreach ($data[$list] ?? [] as $item) {
                if (\is_array($item) && $event = $this->event($item)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $item */
    private function event(array $item): ?ParsedEvent
    {
        $id = (string) ($item['id'] ?? '');
        $startMs = $item['startDate'] ?? $item['structuredContent']['startDate'] ?? null;
        if ('' === $id || !is_numeric($startMs)) {
            return null;
        }

        $location = \is_array($item['location'] ?? null) ? $item['location'] : [];
        $country = self::country($location);
        $timezone = self::TIMEZONES[$country] ?? $this->defaultTimezone;
        $endMs = $item['endDate'] ?? $item['structuredContent']['endDate'] ?? null;
        $start = self::wallClock((int) $startMs, $timezone);
        $end = is_numeric($endMs) ? self::wallClock((int) $endMs, $timezone) : null;
        $body = self::body((string) ($item['body'] ?? ''));
        $source = trim((string) ($item['sourceUrl'] ?? ''));
        $image = trim((string) ($item['assetUrl'] ?? ''));
        $isImage = '' !== $image && str_starts_with((string) ($item['contentType'] ?? 'image/'), 'image/');

        return new ParsedEvent(
            uid: 'squarespace:'.$id,
            summary: self::clean((string) ($item['title'] ?? '')) ?: '—',
            start: $start,
            end: $end && $end > $start ? $end : null,
            timezone: $timezone,
            location: implode(', ', array_filter(array_map(fn ($key) => self::clean((string) ($location[$key] ?? '')), ['addressTitle', 'addressLine1', 'addressLine2', 'addressCountry']))) ?: null,
            url: preg_match('#^https?://#i', $source) ? $source : null,
            lastModified: is_numeric($item['updatedOn'] ?? null) ? self::instant((int) $item['updatedOn']) : null,
            ensemble: $body['ensemble'],
            conductor: $body['conductor'],
            performers: $body['performers'] ?: null,
            programme: $body['programme'] ?: null,
            // Squarespace's CDN gives any width: one a page needs, not the original.
            image: $isImage ? (str_contains($image, 'squarespace-cdn.com') && !str_contains($image, '?') ? $image.'?format=1500w' : $image) : null,
            place: self::place($location, $country),
        );
    }

    /**
     * An entry's HTML body read line by line: each paragraph, each line of
     * it (split on <br>), as (the bold text, the rest).
     *
     * @return array{ensemble: ?string, conductor: ?string, performers: list<string>, programme: list<string>}
     */
    public static function body(string $html): array
    {
        $read = ['ensemble' => null, 'conductor' => null, 'performers' => [], 'programme' => []];
        $composer = null; // a name in bold alone, its work on the next line
        preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $paragraphs);

        foreach ($paragraphs[1] as $paragraph) {
            foreach (preg_split('#<br\s*/?>#i', $paragraph) as $line) {
                preg_match_all('#<strong\b[^>]*>(.*?)</strong>#is', $line, $strong);
                $name = self::clean(implode(' ', $strong[1]));
                $rest = self::clean(preg_replace('#<strong\b[^>]*>.*?</strong>#is', ' ', $line));
                if ('' === $name && '' === $rest) {
                    continue;
                }

                if ('' !== $name && '' === $rest) {
                    if (null === $read['ensemble'] && !$read['programme'] && !$read['performers'] && null === $read['conductor']) {
                        $read['ensemble'] = $name;
                    } else {
                        if ($composer) {
                            $read['programme'][] = $composer;
                        }
                        $composer = $name;
                    }
                    continue;
                }

                if ('' === $name) {
                    $read['programme'][] = $composer ? $composer.' — '.$rest : $rest;
                    $composer = null;
                    continue;
                }

                if ($composer) {
                    $read['programme'][] = $composer;
                    $composer = null;
                }
                if (DescriptionParser::isPart($rest)) {
                    $part = mb_strtolower(trim($rest, " \t.:;,-–—"));
                    if (preg_match('/\b(?:conductor|dirigent|chef|direction|leitung|direttore)\b/u', DescriptionParser::fold($part)) && null === $read['conductor']) {
                        $read['conductor'] = $name;
                    } else {
                        $read['performers'][] = $name.', '.$part;
                    }
                } else {
                    $read['programme'][] = $name.' — '.$rest;
                }
            }
        }
        if ($composer) {
            $read['programme'][] = $composer;
        }

        return $read;
    }

    /** The ISO code of an entry's country: addressCountry, else the last part of its address ("…, Tokyo 107-0052, Japon"). */
    private static function country(array $location): ?string
    {
        $candidates = [(string) ($location['addressCountry'] ?? '')];
        foreach (['addressLine2', 'addressLine1', 'addressTitle'] as $key) {
            $parts = explode(',', (string) ($location[$key] ?? ''));
            $candidates[] = (string) end($parts);
        }
        foreach ($candidates as $candidate) {
            $folded = DescriptionParser::fold(self::clean($candidate));
            if (isset(self::COUNTRIES[$folded])) {
                return self::COUNTRIES[$folded];
            }
            if (preg_match('/^[a-z]{2}$/', $folded) && isset(self::TIMEZONES[strtoupper($folded)])) {
                return strtoupper($folded);
            }
        }

        return null;
    }

    /**
     * The hall as the entry structures it: its title, the street, the town
     * and the postcode of "Hamburg, Hamburg, 20457".
     */
    private static function place(array $location, ?string $country): ?array
    {
        $name = self::clean((string) ($location['addressTitle'] ?? ''));
        if ('' === $name) {
            return null;
        }
        $line2 = array_values(array_filter(array_map(fn (string $part) => self::clean($part), explode(',', (string) ($location['addressLine2'] ?? ''))), fn (string $part) => '' !== $part));
        $postcode = null;
        if ($line2 && preg_match('/\d/', end($line2))) {
            $postcode = array_pop($line2);
        }
        $latitude = is_numeric($location['markerLat'] ?? null) ? (float) $location['markerLat'] : null;
        $longitude = is_numeric($location['markerLng'] ?? null) ? (float) $location['markerLng'] : null;
        if (null !== $latitude && abs($latitude - self::UNPLACED[0]) < 1e-6 && abs($longitude - self::UNPLACED[1]) < 1e-6) {
            $latitude = $longitude = null;
        }

        return [
            'name' => $name,
            'address' => self::clean((string) ($location['addressLine1'] ?? '')) ?: null,
            'postcode' => $postcode,
            'city' => $line2[0] ?? null,
            'country' => $country,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /** A moment in milliseconds read on Berlin's clock, that hour set in the hall's timezone. */
    public static function wallClock(int $milliseconds, string $timezone): \DateTimeImmutable
    {
        $site = self::instant($milliseconds)->setTimezone(new \DateTimeZone(self::SITE_TIMEZONE));

        return new \DateTimeImmutable($site->format('Y-m-d H:i:s'), new \DateTimeZone($timezone));
    }

    private static function instant(int $milliseconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.intdiv($milliseconds, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Text out of HTML: no tag, entities decoded, one space. */
    private static function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{a0}]+/u', ' ', $text));
    }
}
