<?php

namespace Base\Agenda\Tests\Fixtures;

use Base\Agenda\Entity\Event;

/**
 * An Event that keeps its texts in plain properties: omnibase's Thread
 * reads them from a translation entity through the kernel's localizer,
 * which a unit test has none of. Everything the agenda adds is the real one.
 */
class TestEvent extends Event
{
    private ?string $testTitle = null;
    private ?string $testExcerpt = null;
    private ?int $testId = null;
    private ?\DateTimeInterface $testUpdatedAt = null;

    public function __construct(?string $title = null, ?string $slug = null, ?int $id = null)
    {
        // Thread's constructor is not run: it needs the kernel.
        $this->testTitle = $title;
        $this->slug = $slug;
        $this->testId = $id;
        $this->testUpdatedAt = new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC'));
    }

    public static function at(string $start, string $timezone = 'Europe/Berlin', ?string $title = 'A concert', ?string $end = null): self
    {
        $event = new self($title, 'a-concert', 7);
        $event->setTimezone($timezone);
        $event->setStartsAt(new \DateTimeImmutable($start, new \DateTimeZone($timezone)));
        if ($end) {
            $event->setEndsAt(new \DateTimeImmutable($end, new \DateTimeZone($timezone)));
        }

        return $event;
    }

    public function getId(): ?int { return $this->testId; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->testUpdatedAt; }

    public function getTitle(?string $locale = null, int $inheritanceDepthIfNotSet = 0): ?string { return $this->testTitle; }
    public function setTitle(?string $title, ?string $locale = null, int $inheritanceDepthIfNotSet = 0) { $this->testTitle = $title; return $this; }

    public function getExcerpt(?string $locale = null, int $inheritanceDepthIfNotSet = 0): ?string { return $this->testExcerpt; }
    public function setExcerpt(?string $excerpt, ?string $locale = null, int $inheritanceDepthIfNotSet = 0) { $this->testExcerpt = $excerpt; return $this; }

    public function getHeadline(?string $locale = null, int $inheritanceDepthIfNotSet = 0): ?string { return null; }
    public function setHeadline(?string $headline, ?string $locale = null, int $inheritanceDepthIfNotSet = 0) { return $this; }

    public function getContent(?string $locale = null, int $inheritanceDepthIfNotSet = 0): ?string { return null; }
    public function setContent(?string $content, ?string $locale = null, int $inheritanceDepthIfNotSet = 0) { return $this; }

    public function getOwners(): \Doctrine\Common\Collections\Collection { return new \Doctrine\Common\Collections\ArrayCollection(); }
}
