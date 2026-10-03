<?php

namespace Base\Agenda\Model;

/** What a sync did: the dates it opened, the ones it brought up to date, the ones it found cancelled. */
final class ImportSummary
{
    /** @param list<string> $errors a source that could not be read, and why */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $cancelled = 0,
        public int $unchanged = 0,
        public array $errors = [],
    ) {
    }

    public function add(self $other): self
    {
        $this->created += $other->created;
        $this->updated += $other->updated;
        $this->cancelled += $other->cancelled;
        $this->unchanged += $other->unchanged;
        $this->errors = array_merge($this->errors, $other->errors);

        return $this;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->created + $this->updated + $this->cancelled;
    }

    /** @return array{created: int, updated: int, cancelled: int, unchanged: int} */
    public function toArray(): array
    {
        return ['created' => $this->created, 'updated' => $this->updated, 'cancelled' => $this->cancelled, 'unchanged' => $this->unchanged];
    }
}
