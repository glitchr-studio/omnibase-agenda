<?php

namespace Base\Agenda\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Agenda\Service\IcsImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Google Calendar the dates are kept in, in the back office (Agenda ›
 * Google Calendar): its secret address pasted once (the `agenda.ics`
 * setting), the last sync and a button to sync now, and the how-to - how
 * to make the calendar, how to write a date in it so the site files each
 * line where it goes. The artist keeps her dates from her phone and never
 * comes here again.
 */
#[IsGranted('ROLE_ADMIN')]
class CalendarController extends AbstractController
{
    public function __construct(
        private readonly IcsImporter $importer,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/agenda/calendar', name: 'agenda_admin_calendar', methods: ['GET'])]
    public function index(): Response
    {
        $address = $this->importer->getAddress();
        $typed = $address ? array_map('trim', explode(',', $address)) : [];
        // The other calendars, set in the configuration: shown, not edited here.
        $configured = array_values(array_diff($this->importer->getSources(), $typed));

        return $this->page('@Agenda/admin/calendar.html.twig', [
            'address' => $address ? implode(', ', array_map([IcsImporter::class, 'display'], $typed)) : null,
            'configured' => array_map([IcsImporter::class, 'display'], $configured),
            'has_sources' => $this->importer->hasSources(),
            'last' => $this->importer->lastSync(),
        ]);
    }

    /** The address pasted (or forgotten). */
    #[Route('/admin/agenda/calendar', name: 'agenda_admin_calendar_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->assertToken($request, 'agenda-calendar');
        $address = $request->request->getBoolean('forget') ? null : trim($request->request->getString('address'));
        if (null !== $address && !preg_match('#^(?:webcals?|https?)://\S+$#i', $address)) {
            $this->addFlash('danger', $this->translator->trans('admin.calendar.flash.invalid', [], 'agenda'));

            return $this->redirectToRoute('agenda_admin_calendar');
        }

        $this->importer->setAddress($address);
        $this->addFlash('success', $this->translator->trans(null === $address ? 'admin.calendar.flash.forgotten' : 'admin.calendar.flash.saved', [], 'agenda'));

        return $this->redirectToRoute('agenda_admin_calendar');
    }

    /** Every calendar read now, as agenda:sync does every half hour. */
    #[Route('/admin/agenda/calendar/sync', name: 'agenda_admin_calendar_sync', methods: ['POST'])]
    public function sync(Request $request): Response
    {
        $this->assertToken($request, 'agenda-sync');
        if (!$this->importer->hasSources()) {
            $this->addFlash('warning', $this->translator->trans('admin.calendar.flash.no_source', [], 'agenda'));

            return $this->redirectToRoute('agenda_admin_calendar');
        }

        $summary = $this->importer->sync();
        $this->importer->remember($summary);
        foreach ($summary->errors as $error) {
            $this->addFlash('danger', $error);
        }
        $this->addFlash('success', $this->translator->trans('admin.event.flash.synced', $summary->toArray(), 'agenda'));

        return $this->redirectToRoute('agenda_admin_calendar');
    }

    /** A page in the back office's chrome: its menus built when no CRUD did. */
    private function page(string $template, array $parameters): Response
    {
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render($template, ['admin_context' => $this->adminContext] + $parameters);
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }
}
