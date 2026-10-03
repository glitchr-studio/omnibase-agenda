<?php

namespace Base\Agenda\Tests\Service;

use Base\Agenda\Entity\Venue;
use Base\Agenda\Enum\Role;
use Base\Agenda\Service\Ics;
use Base\Agenda\Service\IcsParser;
use Base\Agenda\Tests\Fixtures\TestEvent;
use PHPUnit\Framework\TestCase;

final class IcsTest extends TestCase
{
    private function concert(): TestEvent
    {
        $event = TestEvent::at('2026-11-14 20:00', 'Europe/Berlin', 'Perspectives concertantes — NDR Elbphilharmonie, Hamburg', '2026-11-14 22:00');
        $event->setVenue((new Venue('Elbphilharmonie', 'Hamburg'))->setHall('Großer Saal')->setAddress('Platz der Deutschen Einheit 1')->setPostcode('20457')->setCountry('de'));
        $event->setEnsemble('NDR Elbphilharmonie Orchester');
        $event->setConductor('Alan Gilbert');
        $event->setRole(Role::SOLOIST);
        $event->setProgramme("Glière — Harp Concerto op. 74\n\nDebussy — Danses sacrée et profane; for harp and strings\nRavel — Introduction et allegro, for harp, flute, clarinet and string quartet");
        $event->setPerformers("Anna Meyer — harp");
        $event->setTicketsUrl('https://www.elbphilharmonie.de/en/whats-on/perspectives');

        return $event;
    }

    private function festival(): TestEvent
    {
        $event = new TestEvent('Harp festival', 'harp-festival', 8);
        $event->setSourceUid('festival-2027@example.org');
        $event->setTimezone('Europe/Paris');
        $event->setAllDay(true);
        $event->setStartsAt(new \DateTimeImmutable('2027-07-05 00:00', new \DateTimeZone('Europe/Paris')));
        $event->setEndsAt(new \DateTimeImmutable('2027-07-07 00:00', new \DateTimeZone('Europe/Paris')));
        $event->setCancelled(true);

        return $event;
    }

    public function testACalendarOfTwoDatesIsValidRfc5545(): void
    {
        $ics = (new Ics())->calendar([$this->concert(), $this->festival()], 'Anna Meyer, harp');

        // CRLF everywhere, nothing else breaks a line.
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $ics));
        $this->assertStringNotContainsString("\r", str_replace("\r\n", '', $ics));

        // Folded: no physical line over 75 octets, no character cut in two.
        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(75, \strlen($line), $line);
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), $line);
        }

        $lines = IcsParser::unfold($ics);
        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertContains('VERSION:2.0', $lines);
        $this->assertContains('X-WR-CALNAME:Anna Meyer\, harp', $lines);
        $this->assertSame(2, \count(array_keys($lines, 'BEGIN:VEVENT')));
        $this->assertSame(2, \count(array_keys($lines, 'END:VEVENT')));

        // The hour of the hall, with the VTIMEZONE that defines it.
        $this->assertContains('DTSTART;TZID=Europe/Berlin:20261114T200000', $lines);
        $this->assertContains('DTEND;TZID=Europe/Berlin:20261114T220000', $lines);
        $this->assertContains('TZID:Europe/Berlin', $lines);
        $this->assertContains('TZOFFSETTO:+0100', $lines);

        // Escaped: commas, semicolons, line breaks.
        $this->assertContains('SUMMARY:Perspectives concertantes — NDR Elbphilharmonie\, Hamburg', $lines);
        $description = current(array_filter($lines, fn ($l) => str_starts_with($l, 'DESCRIPTION:')));
        $this->assertStringContainsString('Glière — Harp Concerto op. 74\nDebussy — Danses sacrée et profane\; for harp and strings\nRavel — Introduction et allegro\, for harp', $description);
        $this->assertContains('LOCATION:Elbphilharmonie\, Großer Saal\, Platz der Deutschen Einheit 1\, 20457 Hamburg\, DE', $lines);

        // Whole days: dates, the end the day after the last; cancelled; its own UID kept.
        $this->assertContains('DTSTART;VALUE=DATE:20270705', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20270708', $lines);
        $this->assertContains('STATUS:CANCELLED', $lines);
        $this->assertContains('STATUS:CONFIRMED', $lines);
        $this->assertContains('UID:festival-2027@example.org', $lines);
        $this->assertContains('UID:7@localhost', $lines);
        $this->assertContains('DTSTAMP:20261001T120000Z', $lines);
        $this->assertContains('LAST-MODIFIED:20261001T120000Z', $lines);
    }

    public function testWhatItWritesReadsBack(): void
    {
        $events = (new IcsParser('UTC'))->parse((new Ics())->calendar([$this->concert(), $this->festival()]));

        $this->assertCount(2, $events);
        $this->assertSame('Perspectives concertantes — NDR Elbphilharmonie, Hamburg', $events[0]->summary);
        $this->assertSame('2026-11-14T20:00:00+01:00', $events[0]->start->format('c'));
        $this->assertSame('Europe/Berlin', $events[0]->timezone);
        $this->assertStringContainsString("Debussy — Danses sacrée et profane; for harp and strings\n", $events[0]->description);
        $this->assertFalse($events[0]->cancelled);
        $this->assertTrue($events[1]->allDay);
        $this->assertTrue($events[1]->cancelled);
        $this->assertSame('2027-07-07', $events[1]->end->format('Y-m-d'));
    }

    public function testADateWithNoEndLastsTwoHoursAndUtcStaysUtc(): void
    {
        $event = TestEvent::at('2026-12-01 18:30', 'UTC');
        $lines = IcsParser::unfold((new Ics())->event($event));

        $this->assertContains('DTSTART:20261201T183000Z', $lines);
        $this->assertContains('DURATION:PT2H', $lines);
        $this->assertSame('BEGIN:VEVENT', $lines[0]);
    }

    public function testFoldingNeverCutsACharacter(): void
    {
        $line = 'DESCRIPTION:'.str_repeat('é', 100);
        $folded = Ics::fold($line);

        foreach (explode("\r\n", $folded) as $i => $part) {
            $this->assertLessThanOrEqual(75, \strlen($part));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
            if ($i > 0) {
                $this->assertStringStartsWith(' ', $part);
            }
        }
        $this->assertSame([$line], IcsParser::unfold($folded));
    }

    public function testEscaping(): void
    {
        $this->assertSame('a\\\\b\;c\,d\ne', Ics::escape("a\\b;c,d\ne"));
        $this->assertSame("a\\b;c,d\ne", IcsParser::unescape(Ics::escape("a\\b;c,d\ne")));
    }
}
