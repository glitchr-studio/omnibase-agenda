<?php

namespace Base\Agenda\Tests\Service;

use Base\Agenda\Entity\Venue;
use Base\Agenda\Service\Ics;
use Base\Agenda\Tests\Fixtures\TestEvent;
use PHPUnit\Framework\TestCase;

/** The Google Calendar link of a date, written by glitchr/omnibase's GoogleCalendarLink from the Event. */
final class GoogleCalendarLinkTest extends TestCase
{
    /** @return array<string, string> */
    private function query(string $url): array
    {
        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return $query;
    }

    public function testATimedDateGoesInUtcWithItsTimezone(): void
    {
        $event = TestEvent::at('2026-11-14 20:00', 'Europe/Berlin', 'Perspectives concertantes & co');
        $event->setVenue((new Venue('Elbphilharmonie', 'Hamburg'))->setPostcode('20457'));
        $event->setProgramme('Glière — Harp Concerto op. 74');

        $query = $this->query((new Ics())->google($event));

        $this->assertSame('TEMPLATE', $query['action']);
        $this->assertSame('Perspectives concertantes & co', $query['text']);
        $this->assertSame('20261114T190000Z/20261114T210000Z', $query['dates']); // no end: two hours
        $this->assertSame('Europe/Berlin', $query['ctz']);
        $this->assertSame('Elbphilharmonie, 20457 Hamburg', $query['location']);
        $this->assertSame('Glière — Harp Concerto op. 74', $query['details']);
    }

    public function testItsEndWhenItHasOne(): void
    {
        $event = TestEvent::at('2026-07-01 19:30', 'America/New_York', 'Recital', '2026-07-01 21:15');

        $this->assertSame('20260701T233000Z/20260702T011500Z', $this->query((new Ics())->google($event))['dates']);
    }

    public function testWholeDaysAsDatesTheLastExcluded(): void
    {
        $event = TestEvent::at('2027-07-05 00:00', 'Europe/Paris', 'Festival', '2027-07-07 00:00');
        $event->setAllDay(true);

        $this->assertSame('20270705/20270708', $this->query((new Ics())->google($event))['dates']);
    }
}
