<?php

namespace Base\Agenda\Service;

use Base\Agenda\Entity\Event;

/**
 * The dates by month, in the order given: what the agenda page lists
 * under "October 2026", "November 2026". A date belongs to the month of
 * its first day, as the hall's clock reads it.
 */
final class Months
{
    /**
     * @param iterable<Event> $events
     *
     * @return list<array{month: \DateTimeImmutable, events: list<Event>}>
     */
    public static function group(iterable $events): array
    {
        $months = [];
        foreach ($events as $event) {
            $start = $event->getStartsAtLocal();
            if (!$start) {
                continue;
            }
            $key = $start->format('Y-m');
            $months[$key] ??= ['month' => $start->modify('first day of this month')->setTime(0, 0), 'events' => []];
            $months[$key]['events'][] = $event;
        }

        return array_values($months);
    }
}
