<?php

namespace Base\Agenda\Service;

use Base\Agenda\Entity\Event;
use Base\Agenda\Enum\Role;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A date as schema.org reads it: an event of the type the site is about
 * (agenda.jsonld_type: a musician's MusicEvent - an EducationEvent for a
 * masterclass -, a lecturer's EducationEvent, any other site's Event) with
 * its start and end in ISO 8601 with their offset, the Place and its
 * PostalAddress, the performers, the tickets as an Offer, the cover, and
 * EventCancelled when it will not take place. What the search engines show
 * under the artist's name.
 */
final class JsonLd
{
    public const MUSIC_EVENT = 'MusicEvent';
    public const EDUCATION_EVENT = 'EducationEvent';
    public const EVENT = 'Event';
    public const TYPES = [self::MUSIC_EVENT, self::EDUCATION_EVENT, self::EVENT];

    private readonly string $type;

    public function __construct(#[Autowire('%agenda.jsonld_type%')] string $type = self::MUSIC_EVENT)
    {
        $this->type = \in_array($type, self::TYPES, true) ? $type : self::MUSIC_EVENT;
    }

    /** The schema.org type of every date (a musician's masterclass apart). */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function for(Event $event, ?string $url = null, ?string $image = null, ?string $artist = null): array
    {
        $music = self::MUSIC_EVENT === $this->type;
        $date = fn (?\DateTimeImmutable $at) => $at ? ($event->isAllDay() ? $at->format('Y-m-d') : $at->format(\DATE_ATOM)) : null;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => $music && Role::MASTERCLASS === $event->getRoleEnum() ? self::EDUCATION_EVENT : $this->type,
            'name' => (string) $event->getTitle(),
            'startDate' => $date($event->getStartsAtLocal()),
            'endDate' => $date($event->getEndsAtLocal()),
            'eventStatus' => 'https://schema.org/'.($event->isCancelled() ? 'EventCancelled' : 'EventScheduled'),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url' => $url,
            'image' => $image ? [$image] : null,
            'description' => $event->getExcerpt() ?: ($event->getProgrammeLines() ? implode(' · ', $event->getProgrammeLines()) : null),
        ];

        if ($venue = $event->getVenue()) {
            $data['location'] = array_filter([
                '@type' => 'Place',
                'name' => implode(', ', array_filter([$venue->getName(), $venue->getHall()])),
                'url' => $venue->getWebsite(),
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $venue->getAddress(),
                    'postalCode' => $venue->getPostcode(),
                    'addressLocality' => $venue->getCity(),
                    'addressCountry' => $venue->getCountry(),
                ]),
                'geo' => null !== $venue->getLatitude() && null !== $venue->getLongitude()
                    ? ['@type' => 'GeoCoordinates', 'latitude' => $venue->getLatitude(), 'longitude' => $venue->getLongitude()]
                    : null,
            ]);
        }

        $performers = [];
        if ($artist) {
            $performers[] = ['@type' => 'Person', 'name' => $artist];
        }
        foreach ($event->getPerformerNames() as $performer) {
            if ($performer['name'] !== $artist) {
                $performers[] = ['@type' => 'Person', 'name' => $performer['name']];
            }
        }
        if ($event->getEnsemble()) {
            $performers[] = ['@type' => $music ? 'MusicGroup' : 'PerformingGroup', 'name' => $event->getEnsemble()];
        }
        $data['performer'] = $performers ?: null;

        if ($event->getProgrammeLines()) {
            // A concert's programme is "Composer — Work"; a lecture's, the titles of its talks, as written.
            $data['workPerformed'] = $music
                ? array_map(fn (array $work) => array_filter([
                    '@type' => 'CreativeWork',
                    'name' => $work['work'],
                    'creator' => $work['composer'] ? ['@type' => 'Person', 'name' => $work['composer']] : null,
                ]), $event->getProgrammeWorks())
                : array_map(fn (string $line) => ['@type' => 'CreativeWork', 'name' => $line], $event->getProgrammeLines());
        }

        if ($event->getTicketsUrl()) {
            $data['offers'] = [
                '@type' => 'Offer',
                'url' => $event->getTicketsUrl(),
                'availability' => 'https://schema.org/'.($event->isCancelled() ? 'Discontinued' : 'InStock'),
            ];
        }

        return array_filter($data, fn ($value) => null !== $value && [] !== $value);
    }
}
