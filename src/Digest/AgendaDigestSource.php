<?php

namespace Base\Agenda\Digest;

use Base\Agenda\Entity\Event;
use Base\Agenda\Repository\EventRepository;
use Base\Newsletter\Digest\DigestSourceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The dates of the coming weeks in omnibase/newsletter's digest: the
 * newsletter asks for what falls between two days, the agenda answers with
 * a line per date - the day, the place, with whom. Loaded only when
 * omnibase/newsletter is installed (config/services.php).
 */
final class AgendaDigestSource implements DigestSourceInterface
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getName(): string
    {
        return 'agenda';
    }

    public function digest(\DateTimeImmutable $from, \DateTimeImmutable $to, string $locale): array
    {
        return array_map(fn (Event $event) => [
            'title' => (string) $event->getTitle($locale),
            'text' => $this->text($event, $locale),
            'url' => $this->url($event),
            'date' => $event->getStartsAtLocal(),
        ], $this->events->findBetween($from, $to));
    }

    private function text(Event $event, string $locale): string
    {
        $start = $event->getStartsAtLocal();
        $when = $event->isAllDay()
            ? \IntlDateFormatter::formatObject($start, [\IntlDateFormatter::FULL, \IntlDateFormatter::NONE], $locale)
            : \IntlDateFormatter::formatObject($start, [\IntlDateFormatter::FULL, \IntlDateFormatter::SHORT], $locale);

        return implode(' · ', array_filter([
            $event->isCancelled() ? $this->translator->trans('event.cancelled', [], 'agenda', $locale) : null,
            $when,
            $event->getVenue()?->getLabel(),
            implode(', ', array_filter([$event->getEnsemble(), $event->getConductor()])),
        ]));
    }

    private function url(Event $event): ?string
    {
        try {
            return $this->urls->generate('agenda_event', ['slug' => $event->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return $event->getEventUrl();
        }
    }
}
