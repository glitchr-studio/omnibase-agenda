<?php

namespace Base\Agenda\Tests\Service;

use Base\Agenda\Service\DescriptionParser;
use PHPUnit\Framework\TestCase;

final class DescriptionParserTest extends TestCase
{
    public function testAFrenchDescriptionAsTheHowToWritesIt(): void
    {
        $read = DescriptionParser::parse(<<<'TXT'
            Orchestre : NDR Elbphilharmonie Orchester
            Chef : Vasily Petrenko
            Rôle : soliste
            Billets : https://www.elbphilharmonie.de/fr/programme/12345
            Programme :
            Glière — Concerto pour harpe op. 74
            Debussy — Danses sacrée et profane
            TXT);

        $this->assertSame('NDR Elbphilharmonie Orchester', $read['ensemble']);
        $this->assertSame('Vasily Petrenko', $read['conductor']);
        $this->assertSame('soloist', $read['role']);
        $this->assertSame('https://www.elbphilharmonie.de/fr/programme/12345', $read['tickets']);
        $this->assertSame(['Glière — Concerto pour harpe op. 74', 'Debussy — Danses sacrée et profane'], $read['programme']);
        $this->assertSame([], $read['performers']);
        $this->assertNull($read['rest']);
    }

    public function testAnEnglishOneInAnyCaseWithPerformersOnTheLine(): void
    {
        $read = DescriptionParser::parse(<<<'TXT'
            A season opening, broadcast live.

            ORCHESTRA: London Symphony Orchestra
            conductor: Sir Simon Rattle
            Role: Concertmaster
            With: Anna Meyer, harp, Jonas Weber, violin
            Tickets: book at https://lso.co.uk/whats-on/opening.
            Program: Mahler: Symphony No. 1

            Ravel — La Valse
            TXT);

        $this->assertSame('London Symphony Orchestra', $read['ensemble']);
        $this->assertSame('Sir Simon Rattle', $read['conductor']);
        $this->assertSame('leader', $read['role']);
        $this->assertSame(['Anna Meyer, harp', 'Jonas Weber, violin'], $read['performers']);
        $this->assertSame('https://lso.co.uk/whats-on/opening', $read['tickets']);
        // "Mahler:" is no key: a work; a bare line after a blank one is the programme still.
        $this->assertSame(['Mahler: Symphony No. 1', 'Ravel — La Valse'], $read['programme']);
        $this->assertSame('A season opening, broadcast live.', $read['rest']);
    }

    public function testAGermanOneWithPerformersUnderTheirWord(): void
    {
        $read = DescriptionParser::parse("Orchester: Gürzenich-Orchester Köln\nMusikalische Leitung: François-Xavier Roth\nRolle: Kammermusik\nMit:\nAnna Meyer, Harfe\nJonas Weber, Violine\n\nKarten:\nhttps://www.guerzenich-orchester.de/karten\nProgramm:\nMozart — Flötenquartett D-Dur KV 285");

        $this->assertSame('Gürzenich-Orchester Köln', $read['ensemble']);
        $this->assertSame('François-Xavier Roth', $read['conductor']);
        $this->assertSame('chamber', $read['role']);
        $this->assertSame(['Anna Meyer, Harfe', 'Jonas Weber, Violine'], $read['performers']);
        $this->assertSame('https://www.guerzenich-orchester.de/karten', $read['tickets']);
        $this->assertSame(['Mozart — Flötenquartett D-Dur KV 285'], $read['programme']);
    }

    public function testGooglesHtmlIsReadAsText(): void
    {
        $read = DescriptionParser::parse('<b>Avec&nbsp;:</b> Quatuor Ébène, Anna Meyer, harpe<br>Rôle : musique de chambre<br><br>Programme :<br>Ravel — Introduction et allegro<br><br>Billets : <a href="https://www.google.com/url?q=https://www.philharmoniedeparis.fr/fr/activite/12345&amp;sa=D&amp;source=calendar">ici</a>');

        $this->assertSame(['Quatuor Ébène', 'Anna Meyer, harpe'], $read['performers']);
        $this->assertSame('chamber', $read['role']);
        $this->assertSame(['Ravel — Introduction et allegro'], $read['programme']);
        $this->assertSame('https://www.philharmoniedeparis.fr/fr/activite/12345', $read['tickets']);
    }

    public function testADescriptionNobodyStructuredIsTheRest(): void
    {
        $read = DescriptionParser::parse("Glière — Concerto pour harpe op. 74\nDebussy: Danses sacrée et profane");

        $this->assertNull($read['ensemble']);
        $this->assertSame([], $read['programme']);
        $this->assertSame("Glière — Concerto pour harpe op. 74\nDebussy: Danses sacrée et profane", $read['rest']);
        $this->assertSame(['ensemble' => null, 'conductor' => null, 'role' => null, 'performers' => [], 'tickets' => null, 'programme' => [], 'rest' => null], DescriptionParser::parse(null));
    }

    public function testAnEmptyLineClearsWhatTheSiteHad(): void
    {
        $this->assertSame('', DescriptionParser::parse("Chef :\nOrchestre : Les Siècles")['conductor']);
    }

    public function testTheRolesWordsInFourLanguages(): void
    {
        foreach (['soliste' => 'soloist', 'Solistin' => 'soloist', 'solista' => 'soloist', 'Violon solo' => 'leader', 'Konzertmeisterin' => 'leader', 'primo violino' => 'leader',
            'Musique de chambre' => 'chamber', 'Kammermusik' => 'chamber', 'musica da camera' => 'chamber', 'Orchestre' => 'orchestra', 'Orchester' => 'orchestra',
            'Récital' => 'recital', 'Rezital' => 'recital', 'Masterclass' => 'masterclass', 'Opéra' => 'other'] as $words => $role) {
            $this->assertSame($role, DescriptionParser::role($words), $words);
        }
    }

    public function testWhatAPerformerPlays(): void
    {
        $this->assertTrue(DescriptionParser::isPart('Conductor'));
        $this->assertTrue(DescriptionParser::isPart('Piano & direction'));
        $this->assertTrue(DescriptionParser::isPart('Violoncello '));
        $this->assertFalse(DescriptionParser::isPart('Konzert für Klarinette und Orchester A-Dur KV 622'));
        $this->assertFalse(DescriptionParser::isPart(''));
    }
}
