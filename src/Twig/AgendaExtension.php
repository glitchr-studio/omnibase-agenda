<?php

namespace Base\Agenda\Twig;

use Base\Agenda\Entity\Event;
use Base\Agenda\Enum\Role;
use Base\Agenda\Repository\EventRepository;
use Base\Agenda\Service\Ics;
use Base\Agenda\Service\Months;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What a host's own pages ask the agenda: the next dates for a home page,
 * the very next one for a banner, the two "add to calendar" links of a
 * date, the dates by month, and a role's name.
 */
final class AgendaExtension extends AbstractExtension
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly Ics $ics,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('agenda_upcoming', fn (int $limit = 3): array => $this->events->findUpcoming($limit)),
            new TwigFunction('agenda_next', fn (): ?Event => $this->events->findUpcoming(1)[0] ?? null),
            new TwigFunction('agenda_today', fn (): array => $this->events->findToday()),
            new TwigFunction('agenda_google_url', fn (Event $event): string => $this->ics->google($event)),
            new TwigFunction('agenda_ics_url', fn (Event $event, bool $absolute = false): string => $this->urls->generate('agenda_event_ics', ['slug' => $event->getSlug()], $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH)),
            new TwigFunction('agenda_webcal_url', fn (): string => preg_replace('#^https?://#', 'webcal://', $this->urls->generate('agenda_feed', [], UrlGeneratorInterface::ABSOLUTE_URL))),
            new TwigFunction('agenda_months', fn (iterable $events): array => Months::group($events)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('agenda_role_label', fn (Role|string|null $role): string => $this->translator->trans(Role::of($role)->label(), [], 'agenda')),
        ];
    }
}
