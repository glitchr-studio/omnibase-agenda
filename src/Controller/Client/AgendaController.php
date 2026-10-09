<?php

namespace Base\Agenda\Controller\Client;

use Base\Agenda\Entity\Event;
use Base\Agenda\Repository\EventRepository;
use Base\Agenda\Service\Feed;
use Base\Agenda\Service\Ics;
use Base\Agenda\Service\JsonLd;
use Base\Agenda\Service\Months;
use Base\Attributes\Attribute\Sitemap;
use Base\Service\SettingBagInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The agenda: the dates to come by month and the ones gone by, one date
 * with its programme and its JSON-LD, the calendars to subscribe to
 * (every date) or to add one date to, and the same dates as JSON for
 * other sites and apps (Service\Feed).
 */
class AgendaController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly Ics $ics,
        private readonly JsonLd $jsonLd,
        private readonly Feed $feed,
        private readonly ?SettingBagInterface $settings = null,
        #[Autowire('%agenda.past_per_page%')] private readonly int $pastPerPage = 24,
        #[Autowire('%agenda.show_past%')] private readonly bool $showPast = true,
        #[Autowire('%agenda.jsonld%')] private readonly bool $withJsonLd = true,
        #[Autowire('%agenda.calendar_name%')] private readonly ?string $calendarName = null,
        #[Autowire('%agenda.timezone%')] private readonly string $timezone = 'Europe/Berlin',
    ) {
    }

    #[Sitemap(priority: 0.8, changefreq: 'weekly')]
    #[Route('/agenda', name: 'agenda_index')]
    public function index(Request $request): Response
    {
        $upcoming = $this->events->findUpcoming();
        $pastOpen = $request->query->getBoolean('past');
        $page = max(1, $request->query->getInt('page', 1));
        $past = $this->showPast ? $this->events->findPast($this->pastPerPage, $pastOpen ? $page : 1) : [];

        return $this->render('@Agenda/client/index.html.twig', [
            'upcoming' => $upcoming,
            'months' => Months::group($upcoming),
            'past' => $past,
            'past_months' => Months::group($past),
            'past_open' => $pastOpen,
            'page' => $pastOpen ? $page : 1,
            'pages' => $this->showPast ? max(1, (int) ceil($this->events->countPast() / $this->pastPerPage)) : 1,
            'show_past' => $this->showPast,
        ]);
    }

    #[Route('/agenda.ics', name: 'agenda_feed')]
    public function feed(): Response
    {
        return $this->calendar($this->ics->calendar($this->events->findSince(new \DateTimeImmutable('-1 year')), $this->calendarName()), 'agenda.ics');
    }

    /**
     * The dates as JSON: the ones to come, or `?past=1` the ones gone by
     * (the latest first); `?from=2026-11-01&to=2026-12-31` a span of days;
     * `?limit=` (100, 500 at most) and `?page=`, `next` giving the page after.
     * Anyone may read it, from anywhere (CORS *).
     */
    #[Route('/agenda.json', name: 'agenda_json', methods: ['GET', 'HEAD'])]
    public function list(Request $request): Response
    {
        $limit = min(500, max(1, $request->query->getInt('limit', 100)));
        $page = max(1, $request->query->getInt('page', 1));
        try {
            $from = $this->day($request->query->getString('from'));
            $to = $this->day($request->query->getString('to'));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => '*']);
        }
        $events = $this->events->findForFeed($request->query->getBoolean('past'), $from, $to, $limit, $page);
        $next = null;
        if (\count($events) > $limit) {
            array_pop($events);
            // The query by hand: the router puts a slash before one it is given.
            $next = $this->generateUrl('agenda_json', [], UrlGeneratorInterface::ABSOLUTE_URL).'?'.http_build_query(['page' => $page + 1] + $request->query->all());
        }

        return $this->jsonFeed($request, $this->feed->document($events, $this->calendarName(), $this->timezone, $next, $request->getLocale()));
    }

    /** One date as JSON: what /agenda.json says of it. */
    #[Route('/agenda/{slug}.json', name: 'agenda_event_json', requirements: ['slug' => '[a-z0-9\-]+'], methods: ['GET', 'HEAD'])]
    public function eventJson(Request $request, string $slug): Response
    {
        return $this->jsonFeed($request, $this->feed->event($this->find($slug), $request->getLocale()));
    }

    #[Route('/agenda/{slug}.ics', name: 'agenda_event_ics', requirements: ['slug' => '[a-z0-9\-]+'])]
    public function eventIcs(string $slug): Response
    {
        $event = $this->find($slug);

        return $this->calendar($this->ics->calendar([$event], (string) $event->getTitle()), $slug.'.ics');
    }

    /**
     * A date's cover, said to be the picture it is (image/jpeg, image/png…):
     * the address the feed and the JSON-LD give (Service\Feed::cover). The
     * page's own <img> keeps the file's address, served without PHP.
     */
    #[Route('/agenda/{slug}/cover', name: 'agenda_event_cover', requirements: ['slug' => '[a-z0-9\-]+'], methods: ['GET', 'HEAD'])]
    public function cover(Request $request, string $slug): Response
    {
        $event = $this->find($slug);
        $file = $event->hasCover() ? $event->getCoverFile() : null;
        $type = $file instanceof File && $file->isFile() ? (string) $file->getMimeType() : '';
        if (!str_starts_with($type, 'image/')) {
            throw $this->createNotFoundException(sprintf('No cover for "%s".', $slug));
        }
        $response = new BinaryFileResponse($file, Response::HTTP_OK, [
            'Content-Type' => $type,
            'Access-Control-Allow-Origin' => '*',
            // omnibase's session would turn the response private: it reads none.
            AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
        ], true, null, true, true);
        $response->setMaxAge(86400);
        $response->isNotModified($request);

        return $response;
    }

    #[Sitemap(priority: 0.6, changefreq: 'weekly')]
    #[Route('/agenda/{slug}', name: 'agenda_event', requirements: ['slug' => '[a-z0-9\-]+'])]
    public function event(Request $request, string $slug): Response
    {
        $event = $this->find($slug);
        $jsonLd = null;
        if ($this->withJsonLd) {
            $jsonLd = $this->jsonLd->for(
                $event,
                $this->generateUrl('agenda_event', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->feed->cover($event),
                $this->siteTitle(),
            );
        }

        return $this->render('@Agenda/client/event.html.twig', [
            'event' => $event,
            'jsonld' => $jsonLd,
        ]);
    }

    private function find(string $slug): Event
    {
        return $this->events->findOnePublished($slug)
            ?? throw $this->createNotFoundException(sprintf('No published date "%s".', $slug));
    }

    /**
     * JSON for anyone: CORS open, five minutes in caches, an ETag of what
     * it says (the moment it was written aside) so a reader asking again
     * gets a 304.
     */
    private function jsonFeed(Request $request, array $data): Response
    {
        $tag = $data;
        unset($tag['calendar']['generatedAt']);
        $response = new JsonResponse($data, Response::HTTP_OK, [
            'Access-Control-Allow-Origin' => '*',
            // omnibase's session would turn the response private: it reads none.
            AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
        ]);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $response->setEtag(md5(json_encode($tag, \JSON_UNESCAPED_UNICODE)));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }

    /** "2026-11-01", or none; anything else is the reader's mistake. */
    private function day(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$day || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a day (YYYY-MM-DD).', $value));
        }

        return $day;
    }

    private function calendar(string $content, string $filename): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    /** agenda.calendar_name, else the site's title, else "Agenda". */
    private function calendarName(): string
    {
        return $this->calendarName ?: ($this->siteTitle() ?? 'Agenda');
    }

    private function siteTitle(): ?string
    {
        try {
            $title = $this->settings?->getScalar('base.settings.title');
        } catch (\Throwable) {
            return null; // no settings table yet (a fresh install): no title
        }

        return \is_string($title) && '' !== trim($title) ? trim($title) : null;
    }
}
