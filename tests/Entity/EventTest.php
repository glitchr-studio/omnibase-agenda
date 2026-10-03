<?php

namespace Base\Agenda\Tests\Entity;

use Base\Agenda\Entity\Venue;
use Base\Agenda\Enum\Role;
use Base\Agenda\Service\JsonLd;
use Base\Agenda\Service\Months;
use Base\Agenda\Tests\Fixtures\TestEvent;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testUpcomingUntilMidnightOfItsDayInTheHallsTimezone(): void
    {
        $event = TestEvent::at('2026-11-14 20:00', 'Europe/Berlin');
        $berlin = new \DateTimeZone('Europe/Berlin');

        $this->assertTrue($event->isUpcoming(new \DateTimeImmutable('2026-11-01 10:00', $berlin)));
        $this->assertTrue($event->isUpcoming(new \DateTimeImmutable('2026-11-14 23:30', $berlin)));
        $this->assertFalse($event->isPast(new \DateTimeImmutable('2026-11-14 23:30', $berlin)));
        $this->assertFalse($event->isUpcoming(new \DateTimeImmutable('2026-11-15 00:10', $berlin)));
        $this->assertTrue($event->isPast(new \DateTimeImmutable('2026-11-15 00:10', $berlin)));
    }

    public function testADateOfSeveralDaysIsUpcomingUntilItsLastDay(): void
    {
        $event = TestEvent::at('2027-07-05 00:00', 'Europe/Paris', 'Festival', '2027-07-07 00:00');
        $event->setAllDay(true);
        $paris = new \DateTimeZone('Europe/Paris');

        $this->assertTrue($event->isMultiDay());
        $this->assertTrue($event->isUpcoming(new \DateTimeImmutable('2027-07-07 15:00', $paris)));
        $this->assertTrue($event->isPast(new \DateTimeImmutable('2027-07-08 09:00', $paris)));
    }

    public function testTheStartAsTheHallsClockShowsIt(): void
    {
        $event = new TestEvent('Recital');
        $event->setTimezone('Europe/Berlin');
        $event->setStartsAt(new \DateTimeImmutable('2026-11-14 19:00', new \DateTimeZone('UTC')));

        $this->assertSame('2026-11-14 20:00 +01:00', $event->getStartsAtLocal()->format('Y-m-d H:i P'));
        $this->assertSame('Europe/Berlin', $event->getStartsAtLocal()->getTimezone()->getName());

        $event->setTimezone('Not/AZone');
        $this->assertSame('UTC', $event->getStartsAtLocal()->getTimezone()->getName());
    }

    public function testProgrammeAndPerformersOneALine(): void
    {
        $event = new TestEvent('Concert');
        $event->setProgramme("  Glière — Harp Concerto op. 74 \r\n\r\nDebussy: Danses sacrée et profane\nBach-Busoni chaconne\n");
        $event->setPerformers("Anna Meyer — harp\nJonas Weber, violin\n\nQuatuor Ébène");

        $this->assertSame(['Glière — Harp Concerto op. 74', 'Debussy: Danses sacrée et profane', 'Bach-Busoni chaconne'], $event->getProgrammeLines());
        $this->assertSame([
            ['composer' => 'Glière', 'work' => 'Harp Concerto op. 74'],
            ['composer' => 'Debussy', 'work' => 'Danses sacrée et profane'],
            ['composer' => null, 'work' => 'Bach-Busoni chaconne'],
        ], $event->getProgrammeWorks());
        $this->assertSame(['Anna Meyer — harp', 'Jonas Weber, violin', 'Quatuor Ébène'], $event->getPerformerLines());
        $this->assertSame(['name' => 'Jonas Weber', 'part' => 'violin'], $event->getPerformerNames()[1]);

        $this->assertSame([], (new TestEvent())->getProgrammeLines());
    }

    public function testTheRole(): void
    {
        $event = new TestEvent();
        $this->assertSame(Role::OTHER, $event->getRoleEnum());
        $this->assertSame('soloist', $event->setRole(Role::SOLOIST)->getRole());
        $this->assertSame(Role::CHAMBER, $event->setRole('CHAMBER')->getRoleEnum());
        $this->assertSame('other', $event->setRole('opera')->getRole());
        $this->assertSame('role.recital', Role::RECITAL->label());
    }

    public function testADuplicateKeepsTheVenueAndForgetsTheCalendar(): void
    {
        $event = TestEvent::at('2026-11-14 20:00');
        $event->setVenue($venue = new Venue('Elbphilharmonie', 'Hamburg'));
        $event->setSourceUid('abc@google.com');
        $event->setTicketsUrl('https://example.org/tickets');

        $copy = $event->duplicate(' (copy)');

        $this->assertSame('A concert (copy)', $copy->getTitle());
        $this->assertSame($venue, $copy->getVenue());
        $this->assertEquals($event->getStartsAt(), $copy->getStartsAt());
        $this->assertSame('https://example.org/tickets', $copy->getTicketsUrl());
        $this->assertNull($copy->getSourceUid());
        $this->assertFalse($copy->isCancelled());
    }

    public function testTheDatesByMonth(): void
    {
        $months = Months::group([
            TestEvent::at('2026-10-31 23:30', 'Europe/Berlin', 'A'),
            TestEvent::at('2026-11-02 20:00', 'Europe/Berlin', 'B'),
            TestEvent::at('2026-11-20 20:00', 'Europe/Berlin', 'C'),
        ]);

        $this->assertCount(2, $months);
        $this->assertSame('2026-10-01', $months[0]['month']->format('Y-m-d'));
        $this->assertSame(['B', 'C'], array_map(fn ($e) => $e->getTitle(), $months[1]['events']));
    }

    public function testTheJsonLd(): void
    {
        $event = TestEvent::at('2026-11-14 20:00', 'Europe/Berlin', 'Perspectives concertantes', '2026-11-14 22:00');
        $event->setVenue((new Venue('Elbphilharmonie', 'Hamburg'))->setAddress('Platz der Deutschen Einheit 1')->setCountry('DE'));
        $event->setEnsemble('NDR Elbphilharmonie Orchester');
        $event->setProgramme('Glière — Harp Concerto op. 74');
        $event->setTicketsUrl('https://example.org/tickets');
        $event->setCancelled(true);

        $data = (new JsonLd())->for($event, 'https://anna.example/agenda/a-concert', null, 'Anna Meyer');

        $this->assertSame('MusicEvent', $data['@type']);
        $this->assertSame('2026-11-14T20:00:00+01:00', $data['startDate']);
        $this->assertSame('2026-11-14T22:00:00+01:00', $data['endDate']);
        $this->assertSame('https://schema.org/EventCancelled', $data['eventStatus']);
        $this->assertSame('Hamburg', $data['location']['address']['addressLocality']);
        $this->assertSame('PostalAddress', $data['location']['address']['@type']);
        $this->assertSame([['@type' => 'Person', 'name' => 'Anna Meyer'], ['@type' => 'MusicGroup', 'name' => 'NDR Elbphilharmonie Orchester']], $data['performer']);
        $this->assertSame('https://example.org/tickets', $data['offers']['url']);
        $this->assertSame('Glière', $data['workPerformed'][0]['creator']['name']);
        $this->assertArrayNotHasKey('image', $data);
    }

    /** A lecturer's site: every date an EducationEvent, its programme the talks' titles as written. */
    public function testTheJsonLdTypeIsTheSites(): void
    {
        $event = TestEvent::at('2016-03-04 09:00', 'America/New_York', 'VCTM Annual Conference');
        $event->setRole('masterclass');
        $event->setEnsemble('Virginia Council of Teachers of Mathematics');
        $event->setProgramme("The Beauty of Mathematics\nTeaching Fractions with Meaning: a workshop");

        $data = (new JsonLd(JsonLd::EDUCATION_EVENT))->for($event, null, null, 'Monica Neagoy');
        $this->assertSame('EducationEvent', $data['@type']);
        $this->assertSame([['@type' => 'CreativeWork', 'name' => 'The Beauty of Mathematics'], ['@type' => 'CreativeWork', 'name' => 'Teaching Fractions with Meaning: a workshop']], $data['workPerformed']);
        $this->assertSame('PerformingGroup', $data['performer'][1]['@type']);

        $this->assertSame('Event', (new JsonLd(JsonLd::EVENT))->for($event)['@type']);
        // A musician's masterclass stays an EducationEvent; an unknown type falls back to MusicEvent.
        $this->assertSame('EducationEvent', (new JsonLd())->for($event)['@type']);
        $this->assertSame('MusicEvent', (new JsonLd('Concert'))->getType());
    }

    public function testTodayTonightAndOnStageAreReadInTheVenuesTimezone(): void
    {
        $event = new TestEvent();
        $event->setTimezone('Asia/Taipei');
        $event->setStartsAt(new \DateTimeImmutable('2026-11-25 19:30', new \DateTimeZone('Asia/Taipei')));
        $event->setEndsAt(new \DateTimeImmutable('2026-11-25 21:30', new \DateTimeZone('Asia/Taipei')));

        // 10:00 in Paris is 17:00 in Taipei: the same day there.
        $morning = new \DateTimeImmutable('2026-11-25 10:00', new \DateTimeZone('Europe/Paris'));
        self::assertTrue($event->isToday($morning));
        self::assertTrue($event->isTonight($morning), '19:30 there: tonight');
        self::assertFalse($event->isOnStage($morning));
        self::assertTrue($event->isOnStage(new \DateTimeImmutable('2026-11-25 20:00', new \DateTimeZone('Asia/Taipei'))));
        // 18:00 in Paris is already the 26th in Taipei.
        self::assertFalse($event->isToday(new \DateTimeImmutable('2026-11-25 18:00', new \DateTimeZone('Europe/Paris'))));

        $event->setCancelled(true);
        self::assertFalse($event->isToday($morning), 'a cancelled date is no concert tonight');
    }
}
