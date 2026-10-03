<?php

namespace Base\Agenda\Model;

/**
 * One date as a source gave it - a VEVENT read by Service\IcsParser, an
 * entry of a Squarespace events page, one of another site's /agenda.json -
 * in plain values, no Doctrine. The moments carry their own timezone;
 * `timezone` is the one the entry named (its TZID, else the calendar's),
 * the hall's.
 *
 * What a source says beyond the calendar's own fields (the ensemble, the
 * conductor, the programme...) comes in the nullable properties: null is
 * "not said", and leaves the site's value as it is.
 */
final class ParsedEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly string $summary,
        public readonly \DateTimeImmutable $start,
        /** For whole days: the last day (ICS's own DTEND is the day after). */
        public readonly ?\DateTimeImmutable $end = null,
        public readonly bool $allDay = false,
        public readonly string $timezone = 'UTC',
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly ?string $url = null,
        public readonly ?\DateTimeImmutable $lastModified = null,
        public readonly bool $cancelled = false,
        public readonly int $sequence = 0,
        /** It repeats (RRULE): only its first occurrence is read. */
        public readonly bool $recurring = false,
        public readonly ?string $ensemble = null,
        public readonly ?string $conductor = null,
        /** A Role's value: "soloist", "chamber". */
        public readonly ?string $role = null,
        /** @var list<string>|null one per line: "Anna Meyer, harp" */
        public readonly ?array $performers = null,
        /** @var list<string>|null one work per line: "Glière — Harp Concerto op. 74" */
        public readonly ?array $programme = null,
        public readonly ?string $tickets = null,
        /** A picture's address: the date's cover when it has none. */
        public readonly ?string $image = null,
        /**
         * The hall as the source structures it, when it does: what a Venue is
         * found or opened from instead of splitting `location`.
         *
         * @var array{name: string, hall?: ?string, address?: ?string, postcode?: ?string, city?: ?string, country?: ?string, latitude?: ?float, longitude?: ?float}|null
         */
        public readonly ?array $place = null,
    ) {
    }

    /**
     * The same date with some of its values replaced: what the description
     * says, read once the entry is.
     *
     * @param array<string, mixed> $values
     */
    public function with(array $values): self
    {
        return new self(...array_merge(get_object_vars($this), array_intersect_key($values, get_object_vars($this))));
    }
}
