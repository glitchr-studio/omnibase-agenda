<?php

namespace Base\Agenda\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Agenda\Repository\EventRepository;

/**
 * The dashboard's tile: how many dates are to come, the next five, each
 * opening its record. `yield MenuItem::block('agenda_upcoming', ...)` in the
 * dashboard's configureWidgetItems() places it.
 */
final class UpcomingEventsWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(private readonly EventRepository $events)
    {
    }

    public static function getName(): string
    {
        return 'agenda_upcoming';
    }

    public function getTemplate(): string
    {
        return '@Agenda/admin/widget/upcoming.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        return [
            'count' => $this->events->countUpcoming(),
            'events' => $this->events->findUpcoming(5),
        ];
    }
}
