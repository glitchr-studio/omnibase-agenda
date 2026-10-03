<?php

namespace Base\Agenda\Service;

use Base\Agenda\Entity\Event;
use Base\Agenda\Entity\Venue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The dates as JSON, for the sites and apps that show them elsewhere - an
 * agency's roster, an orchestra's page, a sister site (Service\FeedParser
 * reads it back): /agenda.json and /agenda/{slug}.json. Every moment in
 * ISO 8601 with the hall's offset, every address absolute, the role as
 * its value and its name in the reader's language. Plain arrays, no
 * Response: Controller\Client\AgendaController sends them.
 */
final class Feed
{
    public function __construct(
        private readonly Ics $ics,
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * The whole document: the calendar, its dates, the next page.
     *
     * @param iterable<Event> $events
     *
     * @return array{calendar: array<string, mixed>, events: list<array<string, mixed>>, next: ?string}
     */
    public function document(iterable $events, string $name, string $timezone, ?string $next = null, ?string $locale = null, ?\DateTimeInterface $now = null): array
    {
        return [
            'calendar' => [
                'name' => $name,
                'url' => $this->url('agenda_index'),
                'timezone' => $timezone,
                'ics' => $this->url('agenda_feed'),
                'generatedAt' => \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone($timezone))->format(\DATE_ATOM),
            ],
            'events' => array_values(array_map(fn (Event $event) => $this->event($event, $locale), \is_array($events) ? $events : iterator_to_array($events, false))),
            'next' => $next,
        ];
    }

    /** @return array<string, mixed> one date */
    public function event(Event $event, ?string $locale = null): array
    {
        $date = fn (?\DateTimeImmutable $at) => $at?->format(\DATE_ATOM);
        $slug = $event->getSlug();
        $role = $event->getRoleEnum();

        return [
            'id' => $this->ics->uid($event),
            'slug' => $slug,
            'url' => $slug ? $this->url('agenda_event', ['slug' => $slug]) : null,
            'ics' => $slug ? $this->url('agenda_event_ics', ['slug' => $slug]) : null,
            'title' => (string) $event->getTitle(),
            'start' => $date($event->getStartsAtLocal()),
            'end' => $date($event->getEndsAtLocal()),
            'timezone' => $event->getDateTimeZone()->getName(),
            'allDay' => $event->isAllDay(),
            'cancelled' => $event->isCancelled(),
            'role' => $role->value,
            'roleLabel' => $this->translator?->trans($role->label(), [], 'agenda', $locale) ?? $role->value,
            'ensemble' => $event->getEnsemble(),
            'conductor' => $event->getConductor(),
            'performers' => $event->getPerformerLines(),
            'programme' => $event->getProgrammeLines(),
            'tickets' => $event->getTicketsUrl(),
            'eventUrl' => $event->getEventUrl(),
            'image' => $this->absolute($event->getCoverUrl()),
            'venue' => $this->venue($event->getVenue()),
            'updatedAt' => $event->getUpdatedAt() ? \DateTimeImmutable::createFromInterface($event->getUpdatedAt())->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function venue(?Venue $venue): ?array
    {
        return $venue ? [
            'name' => $venue->getName(),
            'hall' => $venue->getHall(),
            'address' => $venue->getAddress(),
            'postcode' => $venue->getPostcode(),
            'city' => $venue->getCity(),
            'country' => $venue->getCountry(),
            'latitude' => $venue->getLatitude(),
            'longitude' => $venue->getLongitude(),
            'map' => $venue->getMapLink(),
        ] : null;
    }

    /** A path of the site made absolute on the address the request came to; an address stays as it is. */
    public function absolute(?string $path): ?string
    {
        if (null === $path || '' === $path || preg_match('#^(?:https?:)?//#i', $path) || !$this->urls) {
            return $path ?: null;
        }
        $context = $this->urls->getContext();
        $scheme = $context->getScheme();
        $port = 'https' === $scheme ? $context->getHttpsPort() : $context->getHttpPort();
        $default = 'https' === $scheme ? 443 : 80;

        return $scheme.'://'.$context->getHost().($port && $port !== $default ? ':'.$port : '').'/'.ltrim($path, '/');
    }

    private function url(string $route, array $parameters = []): ?string
    {
        try {
            return $this->urls?->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null; // the host did not import that route
        }
    }
}
