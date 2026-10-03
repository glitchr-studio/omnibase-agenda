<?php

namespace Base\Agenda\Service;

use Base\Agenda\Model\ParsedEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A small RFC 5545 reader: enough of it for what a Google Calendar, an
 * Apple one or an orchestra's site publishes. Lines are unfolded, the
 * VEVENT blocks read one by one (VTIMEZONE and VALARM skipped), parameters
 * kept, texts unescaped; a DTSTART with a TZID is read in that timezone, a
 * VALUE=DATE as a day, a trailing Z as UTC, a bare hour in the calendar's
 * X-WR-TIMEZONE (else the default given). A repeating entry gives its first
 * occurrence only. No Doctrine here: Service\IcsImporter does the rest.
 */
final class IcsParser
{
    public function __construct(#[Autowire('%agenda.timezone%')] private readonly string $defaultTimezone = 'Europe/Berlin')
    {
    }

    /** @return list<ParsedEvent> */
    public function parse(string $ics): array
    {
        $calendarTimezone = $this->defaultTimezone;
        $events = [];
        $block = null;
        $depth = 0;

        foreach (self::unfold($ics) as $line) {
            [$name, $params, $value] = self::split($line);

            if ('BEGIN' === $name) {
                if ('VEVENT' === strtoupper($value) && null === $block) {
                    $block = [];
                    $depth = 0;
                } elseif (null !== $block) {
                    ++$depth; // a VALARM inside the event
                }
                continue;
            }
            if ('END' === $name) {
                if (null !== $block && 'VEVENT' === strtoupper($value) && 0 === $depth) {
                    if ($event = $this->event($block, $calendarTimezone)) {
                        $events[] = $event;
                    }
                    $block = null;
                } elseif (null !== $block) {
                    --$depth;
                }
                continue;
            }

            if (null === $block) {
                if ('X-WR-TIMEZONE' === $name && self::zone($value)) {
                    $calendarTimezone = $value;
                }
                continue;
            }
            if (0 === $depth) {
                // The first of each property wins (a second DESCRIPTION is a mistake).
                $block[$name] ??= ['params' => $params, 'value' => $value];
            }
        }

        return $events;
    }

    /**
     * The physical lines, each continuation (a line opened by a space or a
     * tab) glued back onto the one before it.
     *
     * @return list<string>
     */
    public static function unfold(string $ics): array
    {
        $ics = preg_replace('/^\xEF\xBB\xBF/', '', $ics);
        $ics = preg_replace("/\r\n|\r/", "\n", $ics);
        $ics = preg_replace("/\n[ \t]/", '', $ics);

        return array_values(array_filter(explode("\n", $ics), fn (string $line) => '' !== trim($line)));
    }

    /** A text value read back: "\n", "\,", "\;", "\\". */
    public static function unescape(string $text): string
    {
        return preg_replace_callback('/\\\\([\\\;,nN])/', fn (array $m) => 'n' === strtolower($m[1]) ? "\n" : $m[1], $text);
    }

    /**
     * "DTSTART;TZID=Europe/Paris:20261003T200000" → ["DTSTART", ["TZID" => "Europe/Paris"], "20261003T200000"].
     * A colon inside a quoted parameter does not end the name.
     *
     * @return array{0: string, 1: array<string, string>, 2: string}
     */
    public static function split(string $line): array
    {
        $quoted = false;
        $length = \strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            if ('"' === $line[$i]) {
                $quoted = !$quoted;
            } elseif (':' === $line[$i] && !$quoted) {
                break;
            }
        }
        $head = substr($line, 0, $i);
        $value = (string) substr($line, $i + 1);

        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $head);
        $name = strtoupper(trim(array_shift($parts)));
        $params = [];
        foreach ($parts as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
            $params[strtoupper(trim($key))] = trim($val, '"');
        }

        return [$name, $params, $value];
    }

    /** @param array<string, array{params: array<string, string>, value: string}> $block */
    private function event(array $block, string $calendarTimezone): ?ParsedEvent
    {
        if (!isset($block['DTSTART'])) {
            return null;
        }
        [$start, $allDay, $timezone] = $this->moment($block['DTSTART'], $calendarTimezone);
        if (!$start) {
            return null;
        }

        $end = isset($block['DTEND']) ? $this->moment($block['DTEND'], $calendarTimezone)[0] : null;
        if (!$end && isset($block['DURATION'])) {
            $end = self::addDuration($start, $block['DURATION']['value']);
        }
        if ($allDay) {
            // ICS's DTEND of whole days is the day after: keep the last day, none when it is the first.
            $end = $end?->modify('-1 day');
            $end = $end && $end > $start ? $end : null;
        } elseif ($end && $end <= $start) {
            $end = null;
        }

        $summary = trim(self::unescape($block['SUMMARY']['value'] ?? ''));
        $uid = trim($block['UID']['value'] ?? '');
        if ('' === $uid) {
            // No UID (rare, not quite valid): one from what the entry says, stable between two reads.
            $uid = sha1($summary.'|'.$start->format('c')).'@ics';
        }
        $text = fn (string $key) => isset($block[$key]) && '' !== trim($block[$key]['value']) ? trim(self::unescape($block[$key]['value'])) : null;
        $modified = $block['LAST-MODIFIED'] ?? $block['DTSTAMP'] ?? null;

        return new ParsedEvent(
            uid: $uid,
            summary: '' !== $summary ? $summary : '—',
            start: $start,
            end: $end,
            allDay: $allDay,
            timezone: $timezone,
            description: $text('DESCRIPTION'),
            location: $text('LOCATION'),
            url: $text('URL'),
            lastModified: $modified ? $this->moment($modified, 'UTC')[0] : null,
            cancelled: 'CANCELLED' === strtoupper(trim($block['STATUS']['value'] ?? '')),
            sequence: (int) ($block['SEQUENCE']['value'] ?? 0),
            recurring: isset($block['RRULE']),
        );
    }

    /**
     * @param array{params: array<string, string>, value: string} $property
     *
     * @return array{0: ?\DateTimeImmutable, 1: bool, 2: string} the moment, whole days or not, its timezone
     */
    private function moment(array $property, string $calendarTimezone): array
    {
        $value = trim($property['value']);
        $tzid = $property['params']['TZID'] ?? null;
        // A Windows-made TZID ("W. Europe Standard Time") or an unknown one: the calendar's own.
        $timezone = $tzid && self::zone($tzid) ? $tzid : $calendarTimezone;

        if ('DATE' === strtoupper($property['params']['VALUE'] ?? '') || preg_match('/^\d{8}$/', $value)) {
            $day = \DateTimeImmutable::createFromFormat('!Ymd', substr($value, 0, 8), new \DateTimeZone($timezone));

            return [$day ?: null, true, $timezone];
        }

        if (str_ends_with(strtoupper($value), 'Z')) {
            $at = \DateTimeImmutable::createFromFormat('Ymd\THis', substr($value, 0, 15), new \DateTimeZone('UTC'));

            // In UTC: shown in the calendar's timezone, the hall's best guess.
            return [$at ? $at->setTimezone(new \DateTimeZone($timezone)) : null, false, $timezone];
        }

        $at = \DateTimeImmutable::createFromFormat('Ymd\THis', substr($value, 0, 15), new \DateTimeZone($timezone))
            ?: \DateTimeImmutable::createFromFormat('Ymd\THi', substr($value, 0, 13), new \DateTimeZone($timezone));

        return [$at ?: null, false, $timezone];
    }

    /** "PT2H30M", "P1D", "-PT15M" (an alarm's, ignored as an end). */
    private static function addDuration(\DateTimeImmutable $start, string $duration): ?\DateTimeImmutable
    {
        $duration = strtoupper(trim($duration));
        if (str_starts_with($duration, '-')) {
            return null;
        }
        try {
            return $start->add(new \DateInterval(ltrim($duration, '+')));
        } catch (\Exception) {
            return null;
        }
    }

    private static function zone(string $name): bool
    {
        try {
            new \DateTimeZone($name);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
