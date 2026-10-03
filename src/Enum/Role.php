<?php

namespace Base\Agenda\Enum;

/**
 * What the artist does that evening: the soloist of a concerto, the leader
 * of the orchestra (concertmaster, guest leader), a chamber partner, a desk in the orchestra, a recital, a masterclass given. A string
 * enum (not omnibase's EnumType) so the column reads plainly in the database
 * and in the admin's filters.
 */
enum Role: string
{
    case SOLOIST = 'soloist';
    case LEADER = 'leader';
    case CHAMBER = 'chamber';
    case ORCHESTRA = 'orchestra';
    case RECITAL = 'recital';
    case MASTERCLASS = 'masterclass';
    case OTHER = 'other';

    /** The translation key of its name, in the "agenda" domain. */
    public function label(): string
    {
        return 'role.'.$this->value;
    }

    /** Anything a form or a filter hands over, OTHER when it is none of ours. */
    public static function of(self|string|null $role): self
    {
        return $role instanceof self ? $role : (self::tryFrom(strtolower((string) $role)) ?? self::OTHER);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
