<?php

namespace Base\Agenda\Repository;

use Base\Agenda\Entity\Event;
use Base\Enum\ThreadState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Event> */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * The published dates to come, the nearest first. Today's count until
     * midnight, and a date of several days until its last one.
     *
     * @return list<Event>
     */
    public function findUpcoming(?int $limit = null): array
    {
        return $this->upcoming()
            ->orderBy('e.startsAt', 'ASC')->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * The dates on today, wherever they are: a window of a day and a half
     * around now, then each one's own day in its own timezone (Event::isToday).
     *
     * @return list<Event>
     */
    public function findToday(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $candidates = $this->published()
            ->andWhere('e.cancelled = :no')->setParameter('no', false)
            ->andWhere('(e.startsAt BETWEEN :from AND :to) OR (e.endsAt IS NOT NULL AND e.startsAt <= :to AND e.endsAt >= :from)')
            ->setParameter('from', $now->modify('-36 hours'))->setParameter('to', $now->modify('+36 hours'))
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()->getResult();

        return array_values(array_filter($candidates, static fn (Event $event) => $event->isToday($now)));
    }

    public function countUpcoming(): int
    {
        return (int) $this->upcoming()
            ->select('COUNT(e.id)')
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * A page of the published dates gone by, the latest first. Paged by
     * hand: Doctrine's Paginator walks the SQL with an output walker
     * omnibase's own SqlWalker is incompatible with.
     *
     * @return list<Event>
     */
    public function findPast(?int $limit = null, int $page = 1): array
    {
        return $this->past()
            ->orderBy('e.startsAt', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setFirstResult($limit ? max(0, $page - 1) * $limit : 0)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countPast(): int
    {
        return (int) $this->past()
            ->select('COUNT(e.id)')
            ->getQuery()->getSingleScalarResult();
    }

    public function findOnePublished(string $slug): ?Event
    {
        return $this->published()
            ->andWhere('e.slug = :slug')->setParameter('slug', $slug)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * The published dates starting from $from up to (not including) $to,
     * in order: a calendar feed, a newsletter's digest.
     *
     * @return list<Event>
     */
    public function findBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->published()
            ->andWhere('e.startsAt >= :from AND e.startsAt < :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->orderBy('e.startsAt', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** The published dates from $from on, in order: what a subscribed calendar holds. @return list<Event> */
    public function findSince(\DateTimeInterface $from): array
    {
        return $this->published()
            ->andWhere('e.startsAt >= :from')->setParameter('from', $from)
            ->orderBy('e.startsAt', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * A page of the JSON feed: the dates to come (the nearest first), or
     * the ones gone by ($past, the latest first), within [$from, $to] when
     * given (both days included, in the server's timezone). One more than
     * $limit is asked for: whether a next page exists.
     *
     * @return list<Event>
     */
    public function findForFeed(bool $past = false, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 100, int $page = 1): array
    {
        $query = match (true) {
            $past => $this->past(),
            null !== $from || null !== $to => $this->published(),
            default => $this->upcoming(),
        };
        if ($from) {
            $query->andWhere('e.startsAt >= :from OR (e.endsAt IS NOT NULL AND e.endsAt >= :from)')->setParameter('from', $from->setTime(0, 0));
        }
        if ($to) {
            $query->andWhere('e.startsAt < :to')->setParameter('to', $to->setTime(0, 0)->modify('+1 day'));
        }
        $order = $past ? 'DESC' : 'ASC';

        return $query
            ->orderBy('e.startsAt', $order)->addOrderBy('e.id', $order)
            ->setFirstResult(max(0, $page - 1) * $limit)
            ->setMaxResults($limit + 1)
            ->getQuery()->getResult();
    }

    /** Every published date, the latest first: the sitemap's. @return list<Event> */
    public function findAllPublished(int $limit = 5000): array
    {
        return $this->published()
            ->orderBy('e.startsAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** The published dates of one month. @return list<Event> */
    public function findByMonth(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return $this->findBetween($from, $from->modify('+1 month'));
    }

    /** The date read from that calendar entry, whatever its state: the importer's. */
    public function findBySourceUid(string $uid): ?Event
    {
        return $this->findOneBy(['sourceUid' => $uid]);
    }

    /**
     * The dates to come read from one calendar and not cancelled yet: the
     * ones the importer checks are still in it.
     *
     * @return list<Event>
     */
    public function findUpcomingOfSource(string $source): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.source = :source')->setParameter('source', $source)
            ->andWhere('e.cancelled = :no')->setParameter('no', false)
            ->andWhere('e.startsAt >= :today')->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()->getResult();
    }

    private function upcoming(): QueryBuilder
    {
        return $this->published()
            ->andWhere('e.startsAt >= :today OR (e.endsAt IS NOT NULL AND e.endsAt >= :today)')
            ->setParameter('today', new \DateTimeImmutable('today'));
    }

    private function past(): QueryBuilder
    {
        return $this->published()
            ->andWhere('e.startsAt < :today AND (e.endsAt IS NULL OR e.endsAt < :today)')
            ->setParameter('today', new \DateTimeImmutable('today'));
    }

    private function published(): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.state = :published')->setParameter('published', ThreadState::PUBLISH)
            ->andWhere('e.publishedAt IS NULL OR e.publishedAt <= CURRENT_TIMESTAMP()');
    }
}
