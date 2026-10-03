<?php

namespace Base\Agenda\EventListener;

use Base\Agenda\Entity\Event;
use Base\Agenda\Repository\EventRepository;
use Base\Event\SitemapEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The dates' own pages in /sitemap.xml: omnibase lists the routes it can
 * generate by itself (/agenda), and a date is behind a slug it cannot know.
 * Each carries the day it was last changed, so a crawler comes back to the
 * ones that move.
 */
#[AsEventListener(event: SitemapEvent::BUILD)]
final class SitemapListener
{
    public function __construct(private readonly EventRepository $events)
    {
    }

    public function __invoke(SitemapEvent $event): void
    {
        $sitemap = $event->getSitemapper();
        foreach ($this->events->findAllPublished() as $date) {
            /* @var Event $date */
            $sitemap->register('agenda_event', ['slug' => $date->getSlug()], $date->getUpdatedAt()?->format('c'));
        }
    }
}
