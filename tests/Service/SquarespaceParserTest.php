<?php

namespace Base\Agenda\Tests\Service;

use Base\Agenda\Model\ParsedEvent;
use Base\Agenda\Service\SquarespaceParser;
use PHPUnit\Framework\TestCase;

/** A real excerpt of an artist's Squarespace events page (tests/Fixtures/squarespace.json): three dates to come, two gone by. */
final class SquarespaceParserTest extends TestCase
{
    /** @return array<string, ParsedEvent> by uid */
    private function parse(): array
    {
        $events = (new SquarespaceParser('Europe/Berlin'))->parse((string) file_get_contents(__DIR__.'/../Fixtures/squarespace.json'));

        return array_combine(array_map(fn (ParsedEvent $event) => $event->uid, $events), $events);
    }

    public function testEveryDateUpcomingAndPastIsRead(): void
    {
        $this->assertSame([
            'squarespace:6aa6df513f879725ec9b82a2', 'squarespace:6aa857b352d0432878b9aed6', 'squarespace:6aa8598f6290521487dfddcc',
            'squarespace:699dcddcfb615150a4d9fce2', 'squarespace:6a4ff10a95331219232b891f',
        ], array_keys($this->parse()));
        $this->assertTrue(SquarespaceParser::supports(json_decode((string) file_get_contents(__DIR__.'/../Fixtures/squarespace.json'), true)));
        $this->assertFalse(SquarespaceParser::supports(['events' => [], 'calendar' => []]));
    }

    public function testAnOrchestraDateItsPeopleAndItsProgramme(): void
    {
        $event = $this->parse()['squarespace:6aa6df513f879725ec9b82a2'];

        $this->assertSame('M. Fröst, A. Poga | NDR Elbphilharmonie Orchestra', $event->summary);
        $this->assertSame('NDR Elbphilharmonie Orchestra', $event->ensemble);
        $this->assertSame('Andris Poga', $event->conductor);
        $this->assertSame(['Martin Fröst, clarinet'], $event->performers);
        $this->assertSame([
            'Hugo Alfvén — Midsommarvaka - Schwedische Rhapsodie Nr. 1 op. 19',
            'Wolfgang Amadeus Mozart — Konzert für Klarinette und Orchester A-Dur KV 622',
            'Peter Tschaikowsky — Manfred-Sinfonie h-Moll op. 58',
        ], $event->programme);
        $this->assertNull($event->url, 'its sourceUrl is a place, not an address');
        $this->assertNull($event->role, 'the page does not say it');
        $this->assertStringStartsWith('https://images.squarespace-cdn.com/', $event->image);
        $this->assertStringEndsWith('?format=1500w', $event->image);
    }

    public function testTheHourTypedOnBerlinsClockIsTheHallsHour(): void
    {
        $events = $this->parse();

        // Germany: Berlin's hour is the hall's.
        $this->assertSame('2026-10-03T20:00:00+02:00', $events['squarespace:6aa6df513f879725ec9b82a2']->start->format('c'));
        $this->assertSame('Europe/Berlin', $events['squarespace:6aa6df513f879725ec9b82a2']->timezone);
        // Seoul, named only at the end of the address ("Corée du Sud"): 20:00 there, not 20:00 in Berlin.
        $seoul = $events['squarespace:6aa857b352d0432878b9aed6'];
        $this->assertSame('Asia/Seoul', $seoul->timezone);
        $this->assertSame('2026-11-17T20:00:00+09:00', $seoul->start->format('c'));
        // Taïwan, as Squarespace writes the country.
        $this->assertSame('Asia/Taipei', $events['squarespace:6aa8598f6290521487dfddcc']->timezone);
        $this->assertSame('2026-11-25T20:00:00+08:00', $events['squarespace:6aa8598f6290521487dfddcc']->start->format('c'));
        // Estonia, in summer.
        $this->assertSame('2026-07-16T20:00:00+03:00', $events['squarespace:6a4ff10a95331219232b891f']->start->format('c'));
    }

    public function testThePlace(): void
    {
        $event = $this->parse()['squarespace:6aa6df513f879725ec9b82a2'];

        $this->assertSame('Peenemünde, Kraftwerk des Museums, Im Kraftwerk, 10 Fährstraße, Peenemünde, Mecklenburg-Vorpommern, 17449, Allemagne', $event->location);
        $this->assertSame([
            'name' => 'Peenemünde, Kraftwerk des Museums, Im Kraftwerk',
            'address' => '10 Fährstraße',
            'postcode' => '17449',
            'city' => 'Peenemünde',
            'country' => 'DE',
            'latitude' => 54.1384187,
            'longitude' => 13.7653251,
        ], $event->place);

        // No town apart: none made up; Squarespace's New York default is no position.
        $seoul = $this->parse()['squarespace:6aa857b352d0432878b9aed6'];
        $this->assertSame('Lotte Concert Hall', $seoul->place['name']);
        $this->assertNull($seoul->place['city']);
        $this->assertSame('KR', $seoul->place['country']);
    }

    public function testAComposerInBoldOnALineOfItsOwnAndAChamberDate(): void
    {
        $events = $this->parse();

        $rattle = $events['squarespace:699dcddcfb615150a4d9fce2'];
        $this->assertSame('Chamber Orchestra of Europe', $rattle->ensemble);
        $this->assertSame('Sir Simon Rattle', $rattle->conductor);
        $this->assertSame('Béla Bartók — Music for Strings, Percussion and Celesta, Sz 106', $rattle->programme[0]);
        $this->assertCount(3, $rattle->programme);
        $this->assertSame('https://www.elbphilharmonie.de/en/whats-on/chamber-orchestra-of-europe-sir-simon-rattle/23520', $rattle->url);

        $parnu = $events['squarespace:6a4ff10a95331219232b891f'];
        $this->assertSame('Ravel Introduction & Allegro | Pärnu Music Festival Gala', $parnu->summary);
        $this->assertNull($parnu->ensemble, 'no line in bold alone: no orchestra');
        $this->assertSame('Anaëlle Tourret, harp', $parnu->performers[0]);
        $this->assertContains('Mari Poll, Amanda Ernesaks, violin', $parnu->performers);
        $this->assertSame(['Maurice Ravel — Introduction et allegro, M46, for harp, flute, clarinet and string quartet'], $parnu->programme);
    }

    public function testThePagesAndTheJsonAddress(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/squarespace.json'), true);

        $this->assertSame('https://www.anaelletourret.com/performances?offset=1771949630575&format=json', SquarespaceParser::nextPage($data, 'https://www.anaelletourret.com/performances?format=json'));
        $this->assertNull(SquarespaceParser::nextPage(['pagination' => ['nextPage' => false]], 'https://example.org/events'));
        $this->assertSame('https://example.org/events?format=json', SquarespaceParser::jsonUrl('https://example.org/events'));
        $this->assertSame('https://example.org/events?format=json', SquarespaceParser::jsonUrl('https://example.org/events?format=json'));
        $this->assertSame('https://example.org/events?view=list&format=json', SquarespaceParser::jsonUrl('https://example.org/events?view=list'));
    }
}
