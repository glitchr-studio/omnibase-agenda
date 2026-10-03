<?php

namespace Base\Agenda\Tests\Service;

use Base\Agenda\Entity\Venue;
use Base\Agenda\Enum\Role;
use Base\Agenda\Service\Feed;
use Base\Agenda\Service\FeedParser;
use Base\Agenda\Service\Ics;
use Base\Agenda\Tests\Fixtures\TestEvent;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    private function concert(): TestEvent
    {
        $event = TestEvent::at('2026-11-14 20:00', 'Europe/Berlin', 'Perspectives concertantes', '2026-11-14 22:00');
        $event->setVenue((new Venue('Elbphilharmonie', 'Hamburg'))->setHall('Großer Saal')->setAddress('Platz der Deutschen Einheit 1')->setPostcode('20457')->setCountry('DE')->setLatitude(53.5413)->setLongitude(9.9841));
        $event->setEnsemble('NDR Elbphilharmonie Orchester');
        $event->setConductor('Alan Gilbert');
        $event->setRole(Role::SOLOIST);
        $event->setProgramme("Glière — Harp Concerto op. 74\nDebussy — Danses sacrée et profane");
        $event->setPerformers('Anna Meyer, harp');
        $event->setTicketsUrl('https://www.elbphilharmonie.de/tickets');
        $event->setEventUrl('https://www.elbphilharmonie.de/en/whats-on/perspectives');

        return $event;
    }

    public function testADateAsJson(): void
    {
        $data = (new Feed(new Ics()))->event($this->concert());

        $this->assertSame('7@localhost', $data['id']);
        $this->assertSame('a-concert', $data['slug']);
        $this->assertSame('Perspectives concertantes', $data['title']);
        $this->assertSame('2026-11-14T20:00:00+01:00', $data['start']);
        $this->assertSame('2026-11-14T22:00:00+01:00', $data['end']);
        $this->assertSame('Europe/Berlin', $data['timezone']);
        $this->assertFalse($data['allDay']);
        $this->assertFalse($data['cancelled']);
        $this->assertSame('soloist', $data['role']);
        $this->assertSame('soloist', $data['roleLabel'], 'no translator: the value');
        $this->assertSame(['Glière — Harp Concerto op. 74', 'Debussy — Danses sacrée et profane'], $data['programme']);
        $this->assertSame(['Anna Meyer, harp'], $data['performers']);
        $this->assertSame('https://www.elbphilharmonie.de/tickets', $data['tickets']);
        $this->assertNull($data['image']);
        $this->assertSame(['name', 'hall', 'address', 'postcode', 'city', 'country', 'latitude', 'longitude', 'map'], array_keys($data['venue']));
        $this->assertSame('Großer Saal', $data['venue']['hall']);
        $this->assertSame('2026-10-01T12:00:00+00:00', $data['updatedAt']);
        $this->assertSame([
            'id', 'slug', 'url', 'ics', 'title', 'start', 'end', 'timezone', 'allDay', 'cancelled', 'role', 'roleLabel', 'ensemble',
            'conductor', 'performers', 'programme', 'tickets', 'eventUrl', 'image', 'venue', 'updatedAt',
        ], array_keys($data));
    }

    public function testTheDocument(): void
    {
        $data = (new Feed(new Ics()))->document([$this->concert()], 'Anna Meyer', 'Europe/Berlin', 'https://anna.example/agenda.json?page=2', null, new \DateTimeImmutable('2026-10-03 08:00', new \DateTimeZone('UTC')));

        $this->assertSame(['calendar', 'events', 'next'], array_keys($data));
        $this->assertSame('Anna Meyer', $data['calendar']['name']);
        $this->assertSame('2026-10-03T10:00:00+02:00', $data['calendar']['generatedAt']);
        $this->assertCount(1, $data['events']);
        $this->assertSame('https://anna.example/agenda.json?page=2', $data['next']);
    }

    public function testWhatItWritesAnotherSiteReadsBack(): void
    {
        $json = json_encode((new Feed(new Ics()))->document([$this->concert()], 'Anna Meyer', 'Europe/Berlin'));
        $this->assertTrue(FeedParser::supports(json_decode($json, true)));

        [$event] = (new FeedParser())->parse($json);
        $this->assertSame('7@localhost', $event->uid);
        $this->assertSame('Perspectives concertantes', $event->summary);
        $this->assertSame('2026-11-14T20:00:00+01:00', $event->start->format('c'));
        $this->assertSame('Europe/Berlin', $event->timezone);
        $this->assertSame('NDR Elbphilharmonie Orchester', $event->ensemble);
        $this->assertSame('Alan Gilbert', $event->conductor);
        $this->assertSame('soloist', $event->role);
        $this->assertSame(['Anna Meyer, harp'], $event->performers);
        $this->assertCount(2, $event->programme);
        $this->assertSame('https://www.elbphilharmonie.de/tickets', $event->tickets);
        $this->assertSame('https://www.elbphilharmonie.de/en/whats-on/perspectives', $event->url);
        $this->assertSame('Elbphilharmonie', $event->place['name']);
        $this->assertSame('Großer Saal', $event->place['hall']);
        $this->assertSame('Hamburg', $event->place['city']);
        $this->assertSame('DE', $event->place['country']);
        $this->assertSame(53.5413, $event->place['latitude']);
        $this->assertNull(FeedParser::next(['next' => null]));
    }
}
