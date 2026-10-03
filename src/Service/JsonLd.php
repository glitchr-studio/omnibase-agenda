<?php

namespace Base\Agenda\Service;

use Base\Agenda\Entity\Event;
use Base\Agenda\Enum\Role;

/**
 * A date as schema.org reads it: a MusicEvent (an Event for a masterclass)
 * with its start and end in ISO 8601 with their offset, the Place and its
 * PostalAddress, the performers, the tickets as an Offer, the cover, and
 * EventCancelled when it will not take place. What the search engines show
 * under the artist's name.
 */
final class JsonLd
{
    /** @return array<string, mixed> */
    public function for(Event $event, ?string $url = null, ?string $image = null, ?string $artist = null): array
    {
        $date = fn (?\DateTimeImmutable $at) => $at ? ($event->isAllDay() ? $at->format('Y-m-d') : $at->format(\DATE_ATOM)) : null;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => Role::MASTERCLASS === $event->getRoleEnum() ? 'EducationEvent' : 'MusicEvent',
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
            $performers[] = ['@type' => 'MusicGroup', 'name' => $event->getEnsemble()];
        }
        $data['performer'] = $performers ?: null;

        if ($event->getProgrammeLines()) {
            $data['workPerformed'] = array_map(fn (array $work) => array_filter([
                '@type' => 'CreativeWork',
                'name' => $work['work'],
                'creator' => $work['composer'] ? ['@type' => 'Person', 'name' => $work['composer']] : null,
            ]), $event->getProgrammeWorks());
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
