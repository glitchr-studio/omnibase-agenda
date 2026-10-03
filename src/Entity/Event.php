<?php

namespace Base\Agenda\Entity;

use Base\Agenda\Enum\Role;
use Base\Agenda\Repository\EventRepository;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Database\Attribute\Uploader;
use Base\Entity\Thread;
use Base\Service\Calendar\CalendarEntry;
use Base\Service\Model\LinkableInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A date: a concert, a recital, a masterclass - or a school's open day. An
 * omnibase thread - title (the concert's name: "Perspectives concertantes -
 * NDR Elbphilharmonie"), headline, excerpt and text translated, slug,
 * publication state and date - written in the back office
 * (Controller\Admin\Crud\EventCrudController) or read from a calendar
 * (Service\IcsImporter), with on top when it starts, where, with whom,
 * what is played and where the tickets are.
 *
 * The moments are kept in the server's timezone, as Doctrine reads them
 * back; `timezone` is where the hall stands, and what the page shows.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'agenda_event')]
#[ORM\Index(columns: ['startsAt'], name: 'agenda_event_starts_idx')]
#[DiscriminatorEntry(value: 'agenda_event')]
class Event extends Thread implements LinkableInterface
{
    /** How long a date with no end lasts in a calendar. */
    public const DEFAULT_DURATION = 'PT2H';

    /** What parts a line of the programme: "Glière — Harp Concerto op. 74". */
    private const LINE_SEPARATOR = '/\s+[—–-]\s+|\s*:\s+/u';

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-calendar-days'];
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        return $this->getRouter()->generate('agenda_event', array_merge($routeParameters, ['slug' => $this->getSlug()]), $referenceType);
    }

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    protected ?\DateTimeImmutable $startsAt = null;

    /** The end when it is known; for a date of whole days, the last day. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $endsAt = null;

    /** Where the hall stands: the hour shown is the hour there. */
    #[ORM\Column(length: 64)]
    #[Assert\Timezone]
    protected string $timezone = 'Europe/Berlin';

    /** A day (or several) with no hour: a festival, a competition week. */
    #[ORM\Column(type: 'boolean')]
    protected bool $allDay = false;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Venue $venue = null;

    /** The orchestra, the ensemble, the quartet. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    protected ?string $ensemble = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    protected ?string $conductor = null;

    /** A Role's value; a plain string column, so the admin's select and filter read it as it is. */
    #[ORM\Column(length: 24)]
    #[Assert\Choice(callback: [Role::class, 'values'])]
    protected string $role = Role::OTHER->value;

    /** One work per line: "Glière — Harp Concerto op. 74". */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $programme = null;

    /** One per line: "Anna Meyer — harp". */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $performers = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    protected ?string $ticketsUrl = null;

    /** The organiser's own page about it. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    protected ?string $eventUrl = null;

    /** Kept on the page, struck through: who had a ticket must find it. */
    #[ORM\Column(type: 'boolean')]
    protected bool $cancelled = false;

    /** The picture at the top of the date's page: an upload. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '8MB', mime_types: ['image/*'])]
    protected $cover = null;

    /** The UID of the calendar entry it was read from; null: typed by hand. */
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    protected ?string $sourceUid = null;

    /** Which calendar it was read from (IcsImporter::key()), to know what left it. */
    #[ORM\Column(length: 40, nullable: true)]
    protected ?string $source = null;

    /** When that entry last changed in its calendar. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $sourceUpdatedAt = null;

    /**
     * The site's texts are kept: a sync brings the hours and a cancellation
     * from the calendar, nothing else (title, venue, programme... stay as
     * written here).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $syncLocked = false;

    public function __toString(): string
    {
        return $this->getTitle() ?? '';
    }

    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeInterface $startsAt): self { $this->startsAt = self::moment($startsAt); return $this; }

    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeInterface $endsAt): self { $this->endsAt = self::moment($endsAt); return $this; }

    public function getTimezone(): string { return $this->timezone; }
    public function setTimezone(?string $timezone): self { $this->timezone = $timezone ?: 'Europe/Berlin'; return $this; }

    public function isAllDay(): bool { return $this->allDay; }
    public function setAllDay(bool $allDay): self { $this->allDay = $allDay; return $this; }

    public function getVenue(): ?Venue { return $this->venue; }
    public function setVenue(?Venue $venue): self { $this->venue = $venue; return $this; }

    public function getEnsemble(): ?string { return $this->ensemble; }
    public function setEnsemble(?string $ensemble): self { $this->ensemble = $ensemble ?: null; return $this; }

    public function getConductor(): ?string { return $this->conductor; }
    public function setConductor(?string $conductor): self { $this->conductor = $conductor ?: null; return $this; }

    /** The role's value ("soloist"), what the column and the admin's select hold; getRoleEnum() for the enum. */
    public function getRole(): string { return $this->role; }
    public function getRoleEnum(): Role { return Role::of($this->role); }
    public function setRole(Role|string|null $role): self { $this->role = Role::of($role)->value; return $this; }

    public function getProgramme(): ?string { return $this->programme; }
    public function setProgramme(?string $programme): self { $this->programme = $programme ?: null; return $this; }

    public function getPerformers(): ?string { return $this->performers; }
    public function setPerformers(?string $performers): self { $this->performers = $performers ?: null; return $this; }

    public function getTicketsUrl(): ?string { return $this->ticketsUrl; }
    public function setTicketsUrl(?string $ticketsUrl): self { $this->ticketsUrl = $ticketsUrl ?: null; return $this; }

    public function getEventUrl(): ?string { return $this->eventUrl; }
    public function setEventUrl(?string $eventUrl): self { $this->eventUrl = $eventUrl ?: null; return $this; }

    public function isCancelled(): bool { return $this->cancelled; }
    public function setCancelled(bool $cancelled): self { $this->cancelled = $cancelled; return $this; }

    public function getCover(): ?string { return Uploader::getPublic($this, 'cover'); }
    public function getCoverFile(): ?File { return Uploader::get($this, 'cover'); }
    public function setCover($cover): self { $this->cover = $cover; return $this; }
    /** Whether a picture is set, without asking the storage where it is. */
    public function hasCover(): bool { return null !== $this->cover && '' !== $this->cover; }

    /**
     * The cover's address on the site ("/uploads/…"), what an <img> takes:
     * the storage gives its public path from the server's root
     * ("/srv/app/public/uploads/…"), the public directory is taken off.
     */
    public function getCoverUrl(): ?string
    {
        $path = $this->hasCover() ? $this->getCover() : null;
        if (!\is_string($path) || '' === $path || preg_match('#^(?:https?:)?//#i', $path)) {
            return $path ?: null;
        }
        $public = strpos($path, '/public/');

        return false !== $public ? substr($path, $public + \strlen('/public')) : $path;
    }

    public function getSourceUid(): ?string { return $this->sourceUid; }
    public function setSourceUid(?string $sourceUid): self { $this->sourceUid = $sourceUid ?: null; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): self { $this->source = $source ?: null; return $this; }

    public function getSourceUpdatedAt(): ?\DateTimeImmutable { return $this->sourceUpdatedAt; }
    public function setSourceUpdatedAt(?\DateTimeInterface $at): self { $this->sourceUpdatedAt = self::moment($at); return $this; }

    public function isSyncLocked(): bool { return $this->syncLocked; }
    public function setSyncLocked(bool $syncLocked): self { $this->syncLocked = $syncLocked; return $this; }

    /** Read from a calendar, not typed by hand. */
    public function isImported(): bool
    {
        return null !== $this->sourceUid;
    }

    public function getDateTimeZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /** The start as the hall's clock shows it. */
    public function getStartsAtLocal(): ?\DateTimeImmutable
    {
        return $this->startsAt?->setTimezone($this->getDateTimeZone());
    }

    public function getEndsAtLocal(): ?\DateTimeImmutable
    {
        return $this->endsAt?->setTimezone($this->getDateTimeZone());
    }

    /** Several days: a festival, a tour's residency. */
    public function isMultiDay(): bool
    {
        return $this->startsAt && $this->endsAt
            && $this->getStartsAtLocal()->format('Y-m-d') !== $this->getEndsAtLocal()->format('Y-m-d');
    }

    /** Still to come, the whole of its (last) day included: tonight's concert is upcoming until midnight there. */
    public function isUpcoming(?\DateTimeInterface $now = null): bool
    {
        if (!$this->startsAt) {
            return false;
        }
        $today = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())
            ->setTimezone($this->getDateTimeZone())->setTime(0, 0);

        return ($this->getEndsAtLocal() ?? $this->getStartsAtLocal()) >= $today;
    }

    /** On today, in its own timezone (or its run includes today: a festival). */
    public function isToday(?\DateTimeInterface $now = null): bool
    {
        if (!$this->startsAt || $this->cancelled) {
            return false;
        }
        $day = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())->setTimezone($this->getDateTimeZone())->format('Y-m-d');
        $from = $this->getStartsAtLocal()->format('Y-m-d');
        $to = ($this->getEndsAtLocal() ?? $this->getStartsAtLocal())->format('Y-m-d');

        return $from <= $day && $day <= $to;
    }

    /** Today, and in the evening (it starts at 5 pm or later, there): "tonight". */
    public function isTonight(?\DateTimeInterface $now = null): bool
    {
        return $this->isToday($now) && !$this->allDay && (int) $this->getStartsAtLocal()->format('G') >= 17;
    }

    /** Playing right now: started, not over (two hours when no end is given). */
    public function isOnStage(?\DateTimeInterface $now = null): bool
    {
        if (!$this->startsAt || $this->allDay || $this->cancelled) {
            return false;
        }
        $now = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable());

        return $this->startsAt <= $now && $now <= ($this->endsAt ?? $this->startsAt->modify('+2 hours'));
    }

    public function isPast(?\DateTimeInterface $now = null): bool
    {
        return null !== $this->startsAt && !$this->isUpcoming($now);
    }

    /** @return list<string> the works, one per line typed */
    public function getProgrammeLines(): array
    {
        return self::lines($this->programme);
    }

    /** @return list<string> */
    public function getPerformerLines(): array
    {
        return self::lines($this->performers);
    }

    /**
     * The programme with the composer apart from the work, when the line
     * names one ("Glière — Harp Concerto op. 74", "Glière: Harp Concerto").
     *
     * @return list<array{composer: ?string, work: string}>
     */
    public function getProgrammeWorks(): array
    {
        return array_map(function (string $line): array {
            $parts = preg_split(self::LINE_SEPARATOR, $line, 2);

            return 2 === \count($parts) ? ['composer' => $parts[0], 'work' => $parts[1]] : ['composer' => null, 'work' => $line];
        }, $this->getProgrammeLines());
    }

    /**
     * The performers with what they play apart ("Anna Meyer — harp").
     *
     * @return list<array{name: string, part: ?string}>
     */
    public function getPerformerNames(): array
    {
        return array_map(function (string $line): array {
            $parts = preg_split('/\s+[—–-]\s+|\s*,\s+/u', $line, 2);

            return ['name' => $parts[0], 'part' => $parts[1] ?? null];
        }, $this->getPerformerLines());
    }

    /**
     * What a calendar entry says under the title, in plain text: with whom,
     * the programme, the performers, where the tickets are. No labels, so it
     * reads the same in every language.
     */
    public function getDescriptionText(): string
    {
        return implode("\n\n", array_filter([
            implode(' · ', array_filter([$this->ensemble, $this->conductor])),
            implode("\n", $this->getProgrammeLines()),
            implode("\n", $this->getPerformerLines()),
            $this->ticketsUrl,
        ], fn (?string $part) => null !== $part && '' !== $part));
    }

    /**
     * The date as a calendar sees it (glitchr/omnibase's
     * Base\Service\Calendar\CalendarEntry), for its ICS file and its Google
     * Calendar link. Null without a start. $uid: what identifies it in every
     * calendar it lands in; $url: the page it links to.
     */
    public function toCalendarEntry(string $uid, ?string $url = null): ?CalendarEntry
    {
        $start = $this->getStartsAtLocal();
        if (null === $start) {
            return null;
        }
        $venue = $this->getVenue();

        return new CalendarEntry(
            uid: $uid,
            title: (string) $this->getTitle(),
            start: $start,
            end: $this->getEndsAtLocal(),
            allDay: $this->isAllDay(),
            description: $this->getDescriptionText(),
            location: $venue?->getFullAddress(),
            latitude: $venue?->getLatitude(),
            longitude: $venue?->getLongitude(),
            url: $url,
            cancelled: $this->isCancelled(),
            updatedAt: $this->getUpdatedAt(),
            defaultDuration: self::DEFAULT_DURATION,
        );
    }

    /** A copy to start the next date from: same venue, same programme, a draft with no calendar behind it. */
    public function duplicate(string $titleSuffix = ' (copy)'): static
    {
        $copy = new static();
        $copy->setTitle($this->getTitle().$titleSuffix);
        $copy->setHeadline($this->getHeadline());
        $copy->setExcerpt($this->getExcerpt());
        $copy->setContent($this->getContent());
        foreach ($this->getOwners() as $owner) {
            $copy->addOwner($owner);
        }
        $copy->startsAt = $this->startsAt;
        $copy->endsAt = $this->endsAt;
        $copy->timezone = $this->timezone;
        $copy->allDay = $this->allDay;
        $copy->venue = $this->venue;
        $copy->ensemble = $this->ensemble;
        $copy->conductor = $this->conductor;
        $copy->role = $this->role;
        $copy->programme = $this->programme;
        $copy->performers = $this->performers;
        $copy->ticketsUrl = $this->ticketsUrl;
        $copy->eventUrl = $this->eventUrl;

        return $copy;
    }

    /** @return list<string> */
    private static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text)), fn (string $line) => '' !== $line));
    }

    /** Any date, as the immutable moment Doctrine will write: in the server's timezone. */
    private static function moment(?\DateTimeInterface $at): ?\DateTimeImmutable
    {
        return $at ? \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null;
    }
}
