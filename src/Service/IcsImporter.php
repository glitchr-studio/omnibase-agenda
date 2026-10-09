<?php

namespace Base\Agenda\Service;

use Base\Agenda\Entity\Event;
use Base\Agenda\Entity\Venue;
use Base\Agenda\Model\ImportSummary;
use Base\Agenda\Model\ParsedEvent;
use Base\Agenda\Repository\EventRepository;
use Base\Agenda\Repository\VenueRepository;
use Base\Enum\ThreadState;
use Base\Service\SettingBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The dates read from where they are kept - a Google Calendar's secret
 * address (the artist's, from her phone), an agency's ICS feed, a
 * Squarespace events page (the former site's), another omnibase site's
 * /agenda.json - into Events, matched by the entry's UID.
 *
 * The calendar is the source of truth: at each sync a date takes from it
 * its title, its hours, its place (a Venue found or opened), the
 * organiser's link, a cancellation, and what its description says
 * (Service\DescriptionParser: the ensemble, the conductor, the role, the
 * performers, the programme, the tickets) - what it does not say stays as
 * written on the site, and a date marked `syncLocked` takes only its hours
 * and its cancellation. A picture the source has becomes the cover of a
 * date that has none. A new date goes online at once; one that left its
 * calendar is marked cancelled, not deleted.
 */
final class IcsImporter
{
    /** The setting the back office keeps the calendar's address in (Controller\Admin\CalendarController). */
    public const ADDRESS_SETTING = 'agenda.ics';

    /** The setting the last sync's summary is kept in, as JSON. */
    public const LAST_SYNC_SETTING = 'agenda.sync.last';

    /** How many pages of a Squarespace page's past dates are read. */
    private const SQUARESPACE_PAGES = 6;

    /** How many pages of another site's feed are read. */
    private const FEED_PAGES = 20;

    /** The largest picture taken as a cover (the Event's Uploader's limit). */
    private const IMAGE_MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, Venue> the venues of this run, by name and city */
    private array $venues = [];

    /** @param list<string> $sources */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
        private readonly VenueRepository $venueRepository,
        private readonly IcsParser $parser,
        private readonly SquarespaceParser $squarespace,
        private readonly FeedParser $feeds,
        private readonly HttpClientInterface $http,
        private readonly ?SettingBagInterface $settings = null,
        #[Autowire('%agenda.sources%')] private readonly array $sources = [],
        #[Autowire('%agenda.ics%')] private readonly ?string $ics = null,
    ) {
    }

    /**
     * Every calendar to read: agenda.sources, agenda.ics (an environment
     * variable, comma-separated) and the address typed in the back office.
     *
     * @return list<string>
     */
    public function getSources(): array
    {
        $more = array_map('trim', explode(',', (string) $this->ics.','.(string) $this->getAddress()));

        return array_values(array_unique(array_filter([...$this->sources, ...$more])));
    }

    public function hasSources(): bool
    {
        return [] !== $this->getSources();
    }

    /** The address typed in the back office (comma-separated when several), or null. */
    public function getAddress(): ?string
    {
        try {
            $address = $this->settings?->getScalar(self::ADDRESS_SETTING);
        } catch (\Throwable) {
            return null; // no settings table yet: none typed
        }

        return \is_string($address) && '' !== trim($address) ? trim($address) : null;
    }

    public function setAddress(?string $address): void
    {
        $address = null !== $address && '' !== trim($address) ? trim($address) : null;
        $this->settings?->set(self::ADDRESS_SETTING, $address, self::locale());
    }

    /** What the last sync of the calendars did, for the back office: when, and its counts. */
    public function remember(ImportSummary $summary, ?\DateTimeInterface $at = null): void
    {
        try {
            $this->settings?->set(self::LAST_SYNC_SETTING, json_encode([
                'at' => \DateTimeImmutable::createFromInterface($at ?? new \DateTimeImmutable())->format(\DATE_ATOM),
                ...$summary->toArray(),
                'errors' => $summary->errors,
            ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), self::locale());
        } catch (\Throwable) {
            // Not remembered: the sync itself is done.
        }
    }

    /** @return array{at: string, created: int, updated: int, cancelled: int, unchanged?: int, errors: list<string>}|null */
    public function lastSync(): ?array
    {
        try {
            $last = $this->settings?->getScalar(self::LAST_SYNC_SETTING);
        } catch (\Throwable) {
            return null;
        }
        $last = \is_string($last) ? json_decode($last, true) : $last;

        return \is_array($last) && isset($last['at']) ? $last + ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'errors' => []] : null;
    }

    /**
     * Every source configured, or the ones given.
     *
     * @param list<string>|null $sources
     */
    public function sync(?array $sources = null): ImportSummary
    {
        $summary = new ImportSummary();
        foreach ($sources ?? $this->getSources() as $source) {
            try {
                $address = preg_match('#^(webcals?|https?)://#i', trim($source)) ? trim($source) : null;
                $summary->add($this->import($this->read($source), self::key($source), $address));
            } catch (\Throwable $e) {
                // One calendar out of reach: the others are read, and nothing of its own is cancelled.
                $summary->errors[] = sprintf('%s: %s', self::display($source), self::display($e->getMessage()));
                $this->venues = [];
            }
        }

        return $summary;
    }

    /**
     * One source's content, already read: an iCalendar file, a Squarespace
     * events page's JSON or an agenda feed. $source names it, so that the
     * dates which left it can be told from the ones of another calendar;
     * $address, where it was read, is where its next pages are.
     */
    public function import(string $content, string $source = 'ics', ?string $address = null): ImportSummary
    {
        $summary = new ImportSummary();
        $seen = [];
        foreach ($this->parse($content, $address) as $parsed) {
            if (isset($seen[$parsed->uid])) {
                continue; // two pages overlapping
            }
            $seen[$parsed->uid] = true;
            $event = $this->events->findBySourceUid($parsed->uid);

            if (!$event) {
                $event = $this->create($parsed, $source);
                $this->entityManager->persist($event);
                ++$summary->created;
                continue;
            }

            if ($this->update($event, $parsed, $source)) {
                $parsed->cancelled ? ++$summary->cancelled : ++$summary->updated;
            } else {
                ++$summary->unchanged;
            }
        }

        // What is no longer in its calendar did not take place. Only the dates to
        // come are asked: a Squarespace page lists the past ones a few pages deep.
        foreach ($this->events->findUpcomingOfSource($source) as $event) {
            if (!isset($seen[$event->getSourceUid()])) {
                $event->setCancelled(true);
                ++$summary->cancelled;
            }
        }

        $this->entityManager->flush();
        $this->venues = [];

        return $summary;
    }

    /**
     * The dates a content holds, whatever it is: an iCalendar file (each
     * entry's description read for its fields), a Squarespace events page
     * (its past dates followed a few pages back), an agenda feed (its pages
     * followed).
     *
     * @return list<ParsedEvent>
     */
    public function parse(string $content, ?string $address = null): array
    {
        if (str_contains($content, 'BEGIN:VCALENDAR')) {
            return array_map(fn (ParsedEvent $event) => self::described($event), $this->parser->parse($content));
        }

        $data = json_decode(trim($content), true);
        if (SquarespaceParser::supports($data)) {
            $events = $this->squarespace->parse($data);
            for ($page = 1; $address && $page < self::SQUARESPACE_PAGES && ($next = SquarespaceParser::nextPage($data, self::https($address))); ++$page) {
                $data = json_decode($this->fetch($next, 'application/json'), true);
                if (!SquarespaceParser::supports($data)) {
                    break;
                }
                array_push($events, ...$this->squarespace->parse($data));
            }

            return $events;
        }
        if (FeedParser::supports($data)) {
            $events = $this->feeds->parse($data);
            for ($page = 1; $page < self::FEED_PAGES && ($next = FeedParser::next($data)); ++$page) {
                $data = json_decode($this->fetch($next, 'application/json'), true);
                if (!FeedParser::supports($data)) {
                    break;
                }
                array_push($events, ...$this->feeds->parse($data));
            }

            return $events;
        }

        throw new \UnexpectedValueException('neither an iCalendar file, a Squarespace events page nor an agenda feed');
    }

    /**
     * An entry with what its description says filled in: the fields its
     * lines name; the lines no word opens are the programme when none is
     * named (the description of an entry nobody structured).
     */
    public static function described(ParsedEvent $event): ParsedEvent
    {
        if (null === $event->description) {
            return $event;
        }
        $read = DescriptionParser::parse($event->description);
        $programme = $read['programme'] ?: preg_split('/\R/u', (string) $read['rest'], -1, \PREG_SPLIT_NO_EMPTY);

        return $event->with([
            'ensemble' => $read['ensemble'],
            'conductor' => $read['conductor'],
            'role' => $read['role'],
            'performers' => $read['performers'] ?: null,
            'programme' => $programme ?: null,
            'tickets' => $read['tickets'],
        ]);
    }

    /** What names a source in the database: short, stable, nothing of a private address in it. */
    public static function key(string $source): string
    {
        return sha1(trim($source));
    }

    /**
     * "Elbphilharmonie, Platz der Deutschen Einheit 1, 20457 Hamburg, Germany":
     * the name before the first comma, the town in the last part (the one
     * before it when the last is a country: no figure in it, a postcode in
     * the one before), the street in between.
     *
     * @return array{name: string, address: ?string, postcode: ?string, city: ?string}
     */
    public static function splitLocation(string $location): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', str_replace("\n", ',', $location))), fn (string $part) => '' !== $part));
        $name = array_shift($parts) ?? trim($location);
        if (!$parts) {
            return ['name' => $name, 'address' => null, 'postcode' => null, 'city' => null];
        }

        $city = array_pop($parts);
        if ($parts && !preg_match('/\d/', $city) && preg_match('/\d{4,5}/', end($parts))) {
            $city = array_pop($parts);
        }
        $postcode = null;
        if (preg_match('/^(?:[A-Z]{1,2}-)?(\d{4,5})\s+(.+)$/u', $city, $m) || preg_match('/^(.+?)\s+(\d{4,5})$/u', $city, $m)) {
            [$postcode, $city] = ctype_digit($m[1]) ? [$m[1], $m[2]] : [$m[2], $m[1]];
        }

        return ['name' => $name, 'address' => $parts ? implode(', ', $parts) : null, 'postcode' => $postcode, 'city' => '' !== $city ? $city : null];
    }

    private function create(ParsedEvent $parsed, string $source): Event
    {
        // The mapped class: App\Entity\Agenda\Event when the application took over.
        $class = $this->events->getClassName();
        /** @var Event $event */
        $event = new $class();
        $event->setTitle($parsed->summary);
        $event->setSourceUid($parsed->uid);
        $event->setSource($source);
        $this->applyMoment($event, $parsed);
        $this->applyTexts($event, $parsed);
        $event->setState(ThreadState::PUBLISH);
        $event->setPublishedAt(new \DateTime());

        return $event;
    }

    /** @return bool whether anything changed */
    private function update(Event $event, ParsedEvent $parsed, string $source): bool
    {
        $before = $this->fingerprint($event);

        $event->setSource($source);
        $this->applyMoment($event, $parsed);
        if (!$event->isSyncLocked()) {
            if ($event->getTitle() !== $parsed->summary) {
                $event->setTitle($parsed->summary);
            }
            $this->applyTexts($event, $parsed);
        }

        return $before !== $this->fingerprint($event);
    }

    /** What the calendar always owns on a date, locked or not: when, and whether it takes place. */
    private function applyMoment(Event $event, ParsedEvent $parsed): void
    {
        $event->setStartsAt($parsed->start);
        $event->setEndsAt($parsed->end);
        $event->setAllDay($parsed->allDay);
        $event->setTimezone($parsed->timezone);
        $event->setCancelled($parsed->cancelled);
        $event->setSourceUpdatedAt($parsed->lastModified);
    }

    /** What the calendar says of a date that is not locked: what it does not say stays. */
    private function applyTexts(Event $event, ParsedEvent $parsed): void
    {
        if ($parsed->url) {
            $event->setEventUrl($parsed->url);
        }
        if ($venue = $this->venue($parsed)) {
            $event->setVenue($venue);
        }
        if (null !== $parsed->ensemble) {
            $event->setEnsemble(mb_substr($parsed->ensemble, 0, 180));
        }
        if (null !== $parsed->conductor) {
            $event->setConductor(mb_substr($parsed->conductor, 0, 180));
        }
        if (null !== $parsed->role) {
            $event->setRole($parsed->role);
        }
        if (null !== $parsed->performers) {
            $event->setPerformers(implode("\n", $parsed->performers));
        }
        if (null !== $parsed->programme) {
            $event->setProgramme(implode("\n", $parsed->programme));
        }
        if (null !== $parsed->tickets) {
            $event->setTicketsUrl(mb_substr($parsed->tickets, 0, 500));
        }
        $this->cover($event, $parsed->image);
    }

    private function fingerprint(Event $event): string
    {
        return serialize([
            $event->getTitle(), $event->getStartsAt()?->getTimestamp(), $event->getEndsAt()?->getTimestamp(),
            $event->isAllDay(), $event->getTimezone(), $event->getEventUrl(), $event->isCancelled(), $event->getVenue()?->getLabel(),
            $event->getEnsemble(), $event->getConductor(), $event->getRole(), $event->getPerformers(), $event->getProgramme(),
            $event->getTicketsUrl(), $event->hasCover(),
        ]);
    }

    /** The hall of an entry: the one the source structures, else the one its LOCATION names. */
    private function venue(ParsedEvent $parsed): ?Venue
    {
        $place = $parsed->place;
        if (!$place && null !== $parsed->location && '' !== trim($parsed->location)) {
            $place = self::splitLocation($parsed->location);
        }
        if (!$place || '' === trim((string) ($place['name'] ?? ''))) {
            return null;
        }
        $name = mb_substr(trim($place['name']), 0, 180);
        $city = isset($place['city']) && '' !== trim((string) $place['city']) ? mb_substr(trim($place['city']), 0, 120) : null;
        // One house under the spellings a calendar gives it ("Bogota", "Bogotá"), as the database's own comparison would have it.
        $key = Venue::slugify($name, null).'|'.(null !== $city ? Venue::slugify($city, null) : '');

        return $this->venues[$key] ??= $this->venueRepository->findOneByNameAndCity($name, $city)
            ?? $this->openVenue(['name' => $name, 'city' => $city] + $place);
    }

    /** @param array{name: string, city: ?string, hall?: ?string, address?: ?string, postcode?: ?string, country?: ?string, latitude?: ?float, longitude?: ?float} $place */
    private function openVenue(array $place): Venue
    {
        $venue = (new Venue($place['name'], $place['city']))
            ->setHall(isset($place['hall']) ? mb_substr((string) $place['hall'], 0, 180) : null)
            ->setAddress(isset($place['address']) ? mb_substr((string) $place['address'], 0, 255) : null)
            ->setPostcode(isset($place['postcode']) ? mb_substr((string) $place['postcode'], 0, 16) : null)
            ->setCountry(isset($place['country']) && preg_match('/^[A-Za-z]{2}$/', (string) $place['country']) ? $place['country'] : null)
            ->setLatitude($place['latitude'] ?? null)
            ->setLongitude($place['longitude'] ?? null);
        $this->entityManager->persist($venue);

        return $venue;
    }

    /** The source's picture as the cover of a date that has none; nothing when it cannot be had. */
    private function cover(Event $event, ?string $image): void
    {
        if (null === $image || $event->hasCover() || !preg_match('#^https?://#i', $image)) {
            return;
        }
        try {
            $response = $this->http->request('GET', $image, ['timeout' => 20, 'max_duration' => 60]);
            $type = strtolower($response->getHeaders()['content-type'][0] ?? '');
            $content = $response->getContent();
            if ('' === $content || \strlen($content) > self::IMAGE_MAX_BYTES) {
                return;
            }
            // A server that does not say what it sends (application/octet-stream
            // for a file without an extension): the picture says it itself.
            if (!str_starts_with($type, 'image/')) {
                $type = strtolower((string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content));
            }
            if (!str_starts_with($type, 'image/')) {
                return;
            }
            $extension = match (true) {
                str_contains($type, 'png') => 'png',
                str_contains($type, 'webp') => 'webp',
                str_contains($type, 'gif') => 'gif',
                default => 'jpg',
            };
            $tmp = tempnam(sys_get_temp_dir(), 'agenda');
            if (false === $tmp || !rename($tmp, $tmp .= '.'.$extension) || false === file_put_contents($tmp, $content)) {
                return;
            }
            $event->setCover(new File($tmp));
        } catch (\Throwable) {
            // No picture: the date is read all the same.
        }
    }

    /**
     * What a source holds: an address (http, https, webcal) read - a
     * Squarespace page's address gives its HTML, its JSON is asked then -,
     * a file, or the content itself.
     */
    public function read(string $source): string
    {
        $source = trim($source);
        if (str_contains($source, 'BEGIN:VCALENDAR') || str_starts_with($source, '{')) {
            return $source;
        }
        if (preg_match('#^(webcals?|https?)://#i', $source)) {
            $url = self::https($source);
            $content = $this->fetch($url, 'text/calendar, application/json;q=0.9, */*;q=0.5');
            if (preg_match('/^\s*(?:<!doctype|<html)/i', $content) && !preg_match('/[?&]format=json\b/', $url)) {
                // An HTML page: a Squarespace one gives its collection as JSON when asked.
                $content = $this->fetch(SquarespaceParser::jsonUrl($url), 'application/json');
            }

            return $content;
        }
        if (is_file($source) && is_readable($source)) {
            return (string) file_get_contents($source);
        }

        throw new \InvalidArgumentException('neither an address, a readable file nor a calendar');
    }

    /** A source as an error message may show it: no private key of a Google address. */
    public static function display(string $source): string
    {
        $source = trim($source);
        if (str_contains($source, 'BEGIN:VCALENDAR')) {
            return 'ICS content';
        }

        return preg_replace('#/private-[^/\s]+/#', '/private-…/', $source);
    }

    private function fetch(string $url, string $accept): string
    {
        return $this->http->request('GET', $url, [
            'headers' => ['Accept' => $accept],
            'timeout' => 20,
        ])->getContent();
    }

    /** webcal:// is https:// to a client. */
    private static function https(string $address): string
    {
        return preg_replace('#^webcal(s?)://#i', 'https://', trim($address));
    }

    /** The settings' default language: a value written in the back office's is read in every other. */
    private static function locale(): ?string
    {
        try {
            return class_exists(\Base\Service\Localizer::class) ? \Base\Service\Localizer::getDefaultLocale() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
