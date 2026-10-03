<?php

namespace Base\Agenda\Service;

use Base\Agenda\Entity\Event;
use Base\Service\Calendar\CalendarEntry;
use Base\Service\Calendar\GoogleCalendarLink;
use Base\Service\Calendar\Ics as Calendar;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The dates as an iCalendar file, and their Google Calendar links: each
 * Event made a CalendarEntry (its UID, its page on the site) and written by
 * glitchr/omnibase's Base\Service\Calendar - the RFC 5545 writing lives
 * there, once for every bundle.
 */
final class Ics
{
    public const PROD_ID = '-//omnibase//agenda//EN';

    private readonly Calendar $calendar;
    private readonly GoogleCalendarLink $google;

    public function __construct(
        private readonly ?UrlGeneratorInterface $urls = null,
        ?Calendar $calendar = null,
        ?GoogleCalendarLink $google = null,
    ) {
        $this->calendar = $calendar ?? new Calendar();
        $this->google = $google ?? new GoogleCalendarLink();
    }

    /** @param iterable<Event> $events */
    public function calendar(iterable $events, string $name = 'Agenda', string $prodId = self::PROD_ID): string
    {
        $entries = [];
        foreach ($events as $event) {
            if ($entry = $this->entry($event)) {
                $entries[] = $entry;
            }
        }

        return $this->calendar->calendar($entries, $name, $prodId);
    }

    /** One date as a VEVENT block, to put in a VCALENDAR. */
    public function event(Event $event): string
    {
        $entry = $this->entry($event) ?? throw new \InvalidArgumentException('A date with no start has no place in a calendar.');

        return $this->calendar->event($entry);
    }

    /** The "add to Google Calendar" link: the organiser's page in its details, as before. */
    public function google(Event $event): string
    {
        $entry = $event->toCalendarEntry($this->uid($event), $event->getEventUrl())
            ?? new CalendarEntry($this->uid($event), (string) $event->getTitle(), new \DateTimeImmutable('now', $event->getDateTimeZone()), defaultDuration: Event::DEFAULT_DURATION);

        return $this->google->for($entry);
    }

    /** What identifies the date in every calendar it lands in: the entry it was read from, else ours. */
    public function uid(Event $event): string
    {
        return $event->getSourceUid() ?? sprintf('%s@%s', $event->getId() ?? $event->getSlug() ?? spl_object_id($event), $this->host());
    }

    public function entry(Event $event): ?CalendarEntry
    {
        return $event->toCalendarEntry($this->uid($event), $this->url($event));
    }

    public static function escape(string $text): string
    {
        return Calendar::escape($text);
    }

    public static function fold(string $line): string
    {
        return Calendar::fold($line);
    }

    /** The date's page on the site, else the organiser's. */
    private function url(Event $event): ?string
    {
        if ($this->urls && $event->getSlug()) {
            try {
                return $this->urls->generate('agenda_event', ['slug' => $event->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
            } catch (\Throwable) {
                // The host did not import the route: the organiser's page then.
            }
        }

        return $event->getEventUrl();
    }

    private function host(): string
    {
        return $this->urls?->getContext()->getHost() ?: 'localhost';
    }
}
