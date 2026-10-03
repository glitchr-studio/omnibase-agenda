<?php

namespace Base\Agenda\Service;

/**
 * What a calendar entry's description says, read by its lines: the artist
 * writes the date in Google Calendar from her phone, one piece of
 * information per line, opened by a word she knows in her language -
 *
 *     Orchestre : NDR Elbphilharmonie Orchester
 *     Chef : Vasily Petrenko
 *     Rôle : soliste
 *     Billets : https://www.elbphilharmonie.de/...
 *     Programme :
 *     Glière — Concerto pour harpe op. 74
 *
 * - and the site files each where it goes. The words are read whatever
 * their case and accents, in English, French, German and Italian. The
 * lines after "Programme" are the programme until a blank line or another
 * word; a line no word opens is the programme too once a word was read,
 * and before that it is the `rest`. Google sends plain text, sometimes
 * HTML: tags are dropped, a link keeps its address, entities are decoded.
 */
final class DescriptionParser
{
    /** The words a line opens with, folded (lower case, no accent), and the field each fills. */
    private const KEYS = [
        'ensemble' => ['orchestra', 'orchestre', 'orchester', 'ensemble'],
        'conductor' => ['conductor', 'chef', "chef d'orchestre", 'direction', 'direction musicale', 'dirigent', 'leitung', 'musikalische leitung', 'direttore', "direttore d'orchestra"],
        'role' => ['role', 'rolle', 'ruolo'],
        'performers' => ['with', 'avec', 'mit', 'con', 'performers', 'interpretes', 'mitwirkende', 'interpreti'],
        'tickets' => ['tickets', 'billets', 'billetterie', 'karten', 'biglietti'],
        'programme' => ['programme', 'program', 'programm', 'programma'],
    ];

    /** The words of a role, folded, and the Role they name; the first list that matches wins. */
    private const ROLES = [
        'leader' => ['concertmaster', 'leader', 'violon solo', 'konzertmeister', 'konzertmeisterin', 'primo violino', 'premier violon'],
        'soloist' => ['soloist', 'soliste', 'solist', 'solistin', 'solista'],
        'chamber' => ['chamber', 'chambre', 'kammermusik', 'camera'],
        'recital' => ['recital', 'rezital'],
        'masterclass' => ['masterclass', 'master class', 'meisterkurs'],
        'orchestra' => ['orchestra', 'orchestre', 'orchester'],
    ];

    /**
     * What a performer plays or does, folded: "Anna Meyer, harp" is one
     * performer, not two. Squarespace's reader tells a name from a work by them too.
     */
    public const PARTS = [
        'conductor', 'conducting', 'direction', 'chef', 'dirigent', 'leitung', 'direttore', 'piano', 'pianist', 'pianiste', 'klavier', 'pianoforte', 'fortepiano', 'hammerklavier',
        'violin', 'violon', 'violine', 'geige', 'violino', 'viola', 'alto', 'bratsche', 'cello', 'violoncello', 'violoncelle', 'double', 'bass', 'contrebasse', 'kontrabass', 'contrabbasso',
        'harp', 'harpe', 'harfe', 'arpa', 'harpist', 'harpiste', 'flute', 'flote', 'flauto', 'clarinet', 'clarinette', 'klarinette', 'clarinetto', 'oboe', 'hautbois', 'bassoon', 'basson', 'fagott', 'fagotto',
        'horn', 'cor', 'corno', 'trumpet', 'trompette', 'trompete', 'tromba', 'trombone', 'posaune', 'tuba', 'percussion', 'percussions', 'schlagzeug', 'timpani', 'organ', 'orgue', 'orgel', 'organo',
        'harpsichord', 'clavecin', 'cembalo', 'guitar', 'guitare', 'gitarre', 'chitarra', 'lute', 'luth', 'laute', 'saxophone', 'saxophon', 'accordion', 'accordeon', 'akkordeon', 'celesta',
        'soprano', 'sopran', 'mezzo', 'mezzo-soprano', 'mezzosopran', 'contralto', 'countertenor', 'contre-tenor', 'tenor', 'tenore', 'baritone', 'baryton', 'bariton', 'baritono', 'bass-baritone', 'basse', 'basso',
        'voice', 'voix', 'stimme', 'narrator', 'recitant', 'sprecher', 'solo', 'soloist', 'soliste', 'solist', 'and', 'et', 'und', 'e',
    ];

    /**
     * @return array{ensemble: ?string, conductor: ?string, role: ?string, performers: list<string>, tickets: ?string, programme: list<string>, rest: ?string}
     */
    public static function parse(?string $description): array
    {
        $fields = ['ensemble' => null, 'conductor' => null, 'role' => null, 'performers' => [], 'tickets' => null, 'programme' => [], 'rest' => null];
        $rest = [];
        $block = null;   // what the lines under a word with nothing after it are: performers, tickets, programme
        $keyed = false;  // a word was read: a bare line is the programme now

        foreach (preg_split('/\R/u', self::text((string) $description)) as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line));
            if ('' === $line) {
                $block = null;
                continue;
            }

            [$key, $value] = self::key($line);
            if (null === $key) {
                match (true) {
                    'performers' === $block => $fields['performers'][] = $line,
                    'tickets' === $block => [$fields['tickets'], $block] = [self::url($line), null],
                    $keyed => $fields['programme'][] = $line,
                    default => $rest[] = $line,
                };
                continue;
            }

            $keyed = true;
            $block = null;
            switch ($key) {
                case 'performers':
                    '' === $value ? $block = 'performers' : array_push($fields['performers'], ...self::people($value));
                    break;
                case 'programme':
                    $block = 'programme';
                    if ('' !== $value) {
                        $fields['programme'][] = $value;
                    }
                    break;
                case 'tickets':
                    $fields['tickets'] = self::url($value);
                    $block = null === $fields['tickets'] ? 'tickets' : null;
                    break;
                case 'role':
                    $fields['role'] = '' === $value ? null : self::role($value);
                    break;
                default:
                    $fields[$key] = $value; // "" clears what the site had
            }
        }

        $fields['rest'] = $rest ? implode("\n", $rest) : null;

        return $fields;
    }

    /** A Role's value from the words typed: "soliste" → soloist, "Konzertmeister" → leader, anything else → other. */
    public static function role(string $text): string
    {
        $folded = self::fold($text);
        foreach (self::ROLES as $role => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $folded)) {
                    return $role;
                }
            }
        }

        return 'other';
    }

    /** Lower case, no accent, one kind of apostrophe, one space: what a key is compared as. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (class_exists(\Normalizer::class)) {
            $text = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        }

        return preg_replace('/\s+/u', ' ', str_replace(['’', 'ʼ', '`'], "'", strtr($text, ['ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae', 'ø' => 'o'])));
    }

    /** Whether a few words only say what one plays or does: "Conductor", "Piano & direction". */
    public static function isPart(string $text): bool
    {
        $words = preg_split('/[\s,&\/+()]+/u', trim(self::fold($text), " \t.:;-–—"), -1, \PREG_SPLIT_NO_EMPTY);

        return $words && \count($words) <= 5 && !array_diff($words, self::PARTS);
    }

    /** The description as plain text: an HTML one's lines kept, its links as their address. */
    public static function text(string $description): string
    {
        if (preg_match('/<[a-z][^>]*>/i', $description)) {
            $description = preg_replace_callback('#<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function (array $m): string {
                $href = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
                // Google wraps the addresses it links: https://www.google.com/url?q=<the address>&sa=...
                if (preg_match('#^https?://(?:www\.)?google\.[a-z.]+/url\?(?:.*&)?q=([^&]+)#i', $href, $wrapped)) {
                    $href = urldecode($wrapped[1]);
                }
                $text = trim(strip_tags($m[2]));

                return '' === $text || str_contains($text, '://') || $text === $href ? $href : $text.' '.$href;
            }, $description);
            $description = preg_replace('#<br\s*/?>|</(?:p|div|li|h[1-6]|tr)>#i', "\n", $description);
            $description = strip_tags($description);
        }

        return str_replace("\u{a0}", ' ', html_entity_decode($description, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /**
     * The field a line opens, and what follows the colon; [null, null]
     * when no known word opens it ("Debussy: Danses" is a line of programme).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function key(string $line): array
    {
        if (!preg_match('/^[-•*]?\s*([^:：]{2,40}?)\s*[:：]\s*(.*)$/u', $line, $m)) {
            return [null, null];
        }
        $word = self::fold($m[1]);
        foreach (self::KEYS as $field => $words) {
            if (\in_array($word, $words, true)) {
                return [$field, trim($m[2])];
            }
        }

        return [null, null];
    }

    /**
     * "Anna Meyer, harpe, Jonas Weber, violon" → two performers: split on
     * semicolons (else commas), what one plays kept with the name before it.
     *
     * @return list<string>
     */
    private static function people(string $value): array
    {
        $pieces = array_values(array_filter(array_map('trim', preg_split(str_contains($value, ';') ? '/;/' : '/,/', $value)), fn (string $piece) => '' !== $piece));
        if (str_contains($value, ';')) {
            return $pieces;
        }

        $people = [];
        foreach ($pieces as $piece) {
            if ($people && (self::isPart($piece) || preg_match('/^\p{Ll}/u', $piece))) {
                $people[\count($people) - 1] .= ', '.$piece;
            } else {
                $people[] = $piece;
            }
        }

        return $people;
    }

    private static function url(string $text): ?string
    {
        return preg_match('#https?://[^\s<>"]+#i', $text, $m) ? rtrim($m[0], '.,;)') : null;
    }
}
