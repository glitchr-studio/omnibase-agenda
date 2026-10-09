<?php

namespace Base\Agenda\Tests\Service;

use Base\Agenda\Service\IcsImporter;
use Base\Agenda\Service\IcsParser;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase
{
    /** @return list<\Base\Agenda\Model\ParsedEvent> */
    private function parse(): array
    {
        return (new IcsParser('Europe/Vienna'))->parse(file_get_contents(__DIR__.'/../Fixtures/google.ics'));
    }

    public function testAGoogleCalendarExportIsRead(): void
    {
        $this->assertCount(3, $this->parse());
    }

    public function testATimedEntryInItsTzidWithAFoldedDescription(): void
    {
        $event = $this->parse()[0];

        $this->assertSame('7kq1m3c0b8p2@google.com', $event->uid);
        $this->assertSame('Perspectives concertantes — Orchestre de chambre de Paris', $event->summary);
        $this->assertFalse($event->allDay);
        $this->assertSame('Europe/Paris', $event->timezone);
        $this->assertSame('2026-11-14T20:00:00+01:00', $event->start->format('c'));
        $this->assertSame('2026-11-14T22:00:00+01:00', $event->end->format('c'));
        // Unfolded (the space of continuation removed, nothing else), unescaped; the VALARM's DESCRIPTION ignored.
        $this->assertSame(
            "Glière — Concerto pour harpe op. 74\nDebussy — Danses sacrée et profane, pour harpe et cordes\nRavel — Introduction et allegro (avec le Quatuor Ébène)",
            $event->description,
        );
        $this->assertSame('Philharmonie de Paris, 221 Avenue Jean Jaurès, 75019 Paris, France', $event->location);
        $this->assertSame('2026-10-02T18:12:00+00:00', $event->lastModified->format('c'));
        $this->assertSame(2, $event->sequence);
        $this->assertFalse($event->cancelled);
    }

    public function testAnAllDayEntryKeepsItsLastDayAndTheCalendarsTimezone(): void
    {
        $event = $this->parse()[1];

        $this->assertTrue($event->allDay);
        $this->assertSame('Europe/Berlin', $event->timezone); // X-WR-TIMEZONE, not the parser's default
        $this->assertSame('2027-07-05', $event->start->format('Y-m-d'));
        $this->assertSame('2027-07-07', $event->end->format('Y-m-d')); // DTEND 0708 is exclusive
        $this->assertTrue($event->cancelled);
        $this->assertSame('https://example.org/festival', $event->url);
        $this->assertSame('Kurtheater', $event->location);
        $this->assertNull($event->description);
    }

    public function testAUtcEntryWithADuration(): void
    {
        $event = $this->parse()[2];

        $this->assertSame('2026-12-01T19:30:00+01:00', $event->start->format('c'));
        $this->assertSame('2026-12-01T21:00:00+01:00', $event->end->format('c'));
        $this->assertSame('utc-event@example.org', $event->uid);
    }

    public function testASeriesIsItsFirstOccurrenceWhereverItsChangedOnesStand(): void
    {
        // As Google Calendar writes it: the occurrence moved by hand first, under the series' UID, the series after.
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR', 'X-WR-TIMEZONE:Etc/GMT',
            'BEGIN:VEVENT', 'DTSTART;TZID=Europe/Moscow:20180515T190000', 'DTEND;TZID=Europe/Moscow:20180515T210000', 'RECURRENCE-ID;TZID=Europe/Moscow:20180508T190000', 'UID:series@google.com', 'SUMMARY:Moved', 'END:VEVENT',
            'BEGIN:VEVENT', 'DTSTART;TZID=Europe/Moscow:20180410T190000', 'DTEND;TZID=Europe/Moscow:20180410T210000', 'RRULE:FREQ=WEEKLY;BYDAY=TU', 'UID:series@google.com', 'SUMMARY:Every Tuesday', 'END:VEVENT',
            'BEGIN:VEVENT', 'DTSTART:20180601T170000Z', 'RECURRENCE-ID:20180525T170000Z', 'UID:elsewhere@google.com', 'SUMMARY:Its series is not in the file', 'END:VEVENT',
            'END:VCALENDAR',
        ]);
        $events = (new IcsParser('Europe/Paris'))->parse($ics);

        $this->assertCount(2, $events);
        $this->assertSame('series@google.com', $events[0]->uid);
        $this->assertSame('Every Tuesday', $events[0]->summary);
        $this->assertTrue($events[0]->recurring);
        $this->assertSame('2018-04-10T19:00:00+03:00', $events[0]->start->format('c'));
        $this->assertSame('elsewhere@google.com#20180525T170000Z', $events[1]->uid, 'a date of its own');
        $this->assertSame('Etc/GMT', $events[1]->timezone, "the calendar's");
    }

    public function testParametersAndQuotedColons(): void
    {
        $this->assertSame(
            ['ATTENDEE', ['CN' => 'Doe: J', 'ROLE' => 'CHAIR'], 'mailto:j@example.org'],
            IcsParser::split('ATTENDEE;CN="Doe: J";ROLE=CHAIR:mailto:j@example.org'),
        );
    }

    public function testALocationIsSplitIntoAVenue(): void
    {
        $this->assertSame(
            ['name' => 'Philharmonie de Paris', 'address' => '221 Avenue Jean Jaurès', 'postcode' => '75019', 'city' => 'Paris'],
            IcsImporter::splitLocation('Philharmonie de Paris, 221 Avenue Jean Jaurès, 75019 Paris, France'),
        );
        $this->assertSame(
            ['name' => 'Elbphilharmonie', 'address' => null, 'postcode' => null, 'city' => 'Hamburg'],
            IcsImporter::splitLocation('Elbphilharmonie, Hamburg'),
        );
        $this->assertSame(
            ['name' => 'Kurtheater', 'address' => null, 'postcode' => null, 'city' => null],
            IcsImporter::splitLocation('Kurtheater'),
        );
    }

    public function testAPrivateAddressIsNotShownInAnError(): void
    {
        $this->assertSame(
            'https://calendar.google.com/calendar/ical/abc%40group.calendar.google.com/private-…/basic.ics',
            IcsImporter::display('https://calendar.google.com/calendar/ical/abc%40group.calendar.google.com/private-0123456789abcdef/basic.ics'),
        );
    }

    public function testAGoogleEntryDescribedLineByLine(): void
    {
        $events = array_map(
            [IcsImporter::class, 'described'],
            (new IcsParser('Europe/Berlin'))->parse((string) file_get_contents(__DIR__.'/../Fixtures/google-structured.ics')),
        );

        $this->assertCount(2, $events);
        [$hamburg, $paris] = $events;
        $this->assertSame('2027-02-12T20:00:00+01:00', $hamburg->start->format('c'));
        $this->assertSame('NDR Elbphilharmonie Orchester', $hamburg->ensemble);
        $this->assertSame('Vasily Petrenko', $hamburg->conductor);
        $this->assertSame('soloist', $hamburg->role);
        $this->assertSame('https://www.elbphilharmonie.de/fr/programme/perspectives-concertantes/12345', $hamburg->tickets);
        $this->assertSame(['Glière — Concerto pour harpe op. 74', 'Debussy — Danses sacrée et profane'], $hamburg->programme);
        $this->assertNull($hamburg->performers);

        // Google's HTML: the bold word, the line breaks, its wrapped link.
        $this->assertSame(['Quatuor Ébène', 'Anna Meyer, harpe'], $paris->performers);
        $this->assertSame('chamber', $paris->role);
        $this->assertSame(['Ravel — Introduction et allegro', 'Debussy — Sonate pour flûte, alto et harpe'], $paris->programme);
        $this->assertSame('https://www.philharmoniedeparis.fr/fr/activite/12345', $paris->tickets);
        $this->assertNull($paris->ensemble);
    }

    public function testAnUnstructuredDescriptionIsTheProgrammeAsBefore(): void
    {
        $event = IcsImporter::described($this->parse()[0]);

        $this->assertCount(3, $event->programme);
        $this->assertSame('Glière — Concerto pour harpe op. 74', $event->programme[0]);
        $this->assertNull($event->ensemble);
        $this->assertNull(IcsImporter::described($this->parse()[1])->programme, 'no description, nothing said');
    }
}
