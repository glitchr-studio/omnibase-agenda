# ICS feeds and Google Calendar links

The iCalendar writing (RFC 5545) and the Google Calendar link are
glitchr/omnibase's `Base\Service\Calendar\{Ics, GoogleCalendarLink}`, on a
`CalendarEntry`. The agenda maps its dates there:

- `Event::toCalendarEntry(string $uid, ?string $url)`: the date as a calendar
  sees it (the hall's timezone, whole days, the venue's address and GPS point,
  cancelled or not, `Event::DEFAULT_DURATION` without an end).
- `Base\Agenda\Service\Ics`: `calendar($events, $name)` and `event($event)` (the
  `/agenda.ics` and `/agenda/{slug}.ics` feeds), `google($event)` (the Twig
  function `agenda_google_url()`), `uid($event)` (the source's UID, else
  `<id>@<host>`), `entry($event)`. `Ics::escape()` and `Ics::fold()` are the
  core's.

Nothing to configure. The bundle's tests run from an application that installs
it (`tests/bootstrap.php` registers the test namespace):

```
docker exec anaelletourret-web-1 php vendor/bin/phpunit -c vendor/omnibase/agenda/phpunit.xml.dist
```
