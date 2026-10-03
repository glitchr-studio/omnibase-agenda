<?php

namespace Base\Agenda\Repository;

use Base\Agenda\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Venue> */
class VenueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Venue::class);
    }

    /** The house of that name in that town (whatever the case), so an import does not open it twice. */
    public function findOneByNameAndCity(string $name, ?string $city = null): ?Venue
    {
        $query = $this->createQueryBuilder('v')
            ->andWhere('LOWER(v.name) = :name')->setParameter('name', mb_strtolower(trim($name)))
            ->orderBy('v.id', 'ASC')
            ->setMaxResults(1);
        $query = null !== $city && '' !== trim($city)
            ? $query->andWhere('LOWER(v.city) = :city')->setParameter('city', mb_strtolower(trim($city)))
            : $query->andWhere('v.city IS NULL');

        return $query->getQuery()->getOneOrNullResult();
    }

    /** @return list<Venue> by name, for a select */
    public function findAllByName(): array
    {
        return $this->findBy([], ['name' => 'ASC', 'city' => 'ASC']);
    }
}
