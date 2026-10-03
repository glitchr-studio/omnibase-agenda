# Structured data (JSON-LD)

Each date's page (`agenda_event`) carries a schema.org event in a
`<script type="application/ld+json">`, written by `Service\JsonLd` from the
`Event`: its name, its start and end in ISO 8601 with the hall's offset (a
date of whole days as days), `eventStatus` (`EventCancelled` when cancelled),
the `Place` and its `PostalAddress` (and `GeoCoordinates` when the venue has
them), the cover, the tickets as an `Offer`, the performers - the site's own
name first, then each performer, then the ensemble -, and the programme as
`workPerformed`.

## The type

```yaml
# config/packages/agenda.yaml
agenda:
    jsonld: true                  # false: no JSON-LD at all
    jsonld_type: EducationEvent   # MusicEvent (default), EducationEvent, Event
```

| `jsonld_type` | For | `@type` | Ensemble | Programme |
|---|---|---|---|---|
| `MusicEvent` (default) | a musician | `MusicEvent`; a date whose role is *masterclass*: `EducationEvent` | `MusicGroup` | each line "Composer — Work": a `CreativeWork` with its `creator` |
| `EducationEvent` | a lecturer, a teacher trainer, a school | `EducationEvent` | `PerformingGroup` | each line a `CreativeWork` named as written (the talks' titles) |
| `Event` | anything else | `Event` | `PerformingGroup` | idem |

An unknown value is refused by the configuration; `new JsonLd('…')` outside
the container falls back to `MusicEvent`.

## Outside the page

```php
use Base\Agenda\Service\JsonLd;

public function __construct(private readonly JsonLd $jsonLd) {}

$data = $this->jsonLd->for($event, $absoluteUrl, $absoluteImageUrl, 'Monica Neagoy');
$this->jsonLd->getType();   // 'EducationEvent'
```

The fourth argument is the person or group the site is about: the first
performer of every date.
