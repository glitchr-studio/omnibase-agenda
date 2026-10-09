<?php

namespace Base\Agenda\Entity;

use Base\Agenda\Repository\VenueRepository;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where it happens: a hall, a church, a school's auditorium. Not a thread -
 * a name, a room in it, an address, the map and the house's own site - and
 * shared by every date played there, so it is typed once.
 */
#[ORM\Entity(repositoryClass: VenueRepository::class)]
#[ORM\Table(name: 'agenda_venue')]
#[ORM\HasLifecycleCallbacks]
class Venue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    protected ?string $name = null;

    /** The room inside the house: "Großer Saal", "Salle Pierre Boulez". */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    protected ?string $hall = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    protected ?string $address = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Assert\Length(max: 16)]
    protected ?string $postcode = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    protected ?string $city = null;

    /** ISO 3166-1 alpha-2: DE, FR, CH. */
    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Country]
    protected ?string $country = null;

    /** A link to the map of one's choice; without it the address is searched. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    protected ?string $mapUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 255)]
    protected ?string $website = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    protected ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    protected ?float $longitude = null;

    /** From the name and the city: "elbphilharmonie-hamburg". */
    #[ORM\Column(length: 255, unique: true)]
    protected ?string $slug = null;

    public function __construct(?string $name = null, ?string $city = null)
    {
        $this->name = $name;
        $this->city = $city;
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }

    /** The slug the name and the city make, before it is made unique. */
    public static function slugify(?string $name, ?string $city): string
    {
        $slug = strtolower((string) (new AsciiSlugger())->slug(trim($name.' '.$city)));

        return '' !== $slug ? $slug : 'venue';
    }

    /**
     * The slug is given once, when the venue is first saved: "-2" when another
     * house has it - one already saved, or one waiting to be with this one (a
     * calendar read opens many at once: "Bogota" and "Bogotá" make one slug).
     */
    #[ORM\PrePersist]
    public function giveSlug(PrePersistEventArgs $event): void
    {
        if ($this->slug) {
            return;
        }
        $base = self::slugify($this->name, $this->city);
        $manager = $event->getObjectManager();
        $repository = $manager->getRepository(self::class);
        $waiting = [];
        foreach ($manager->getUnitOfWork()->getScheduledEntityInsertions() as $other) {
            if ($other instanceof self && $other !== $this && $other->slug) {
                $waiting[$other->slug] = true;
            }
        }
        $slug = $base;
        for ($i = 2; isset($waiting[$slug]) || $repository->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }
        $this->slug = $slug;
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getHall(): ?string { return $this->hall; }
    public function setHall(?string $hall): self { $this->hall = $hall ?: null; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = $address ?: null; return $this; }

    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $postcode): self { $this->postcode = $postcode ?: null; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = $city ?: null; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): self { $this->country = $country ? strtoupper($country) : null; return $this; }

    public function getMapUrl(): ?string { return $this->mapUrl; }
    public function setMapUrl(?string $mapUrl): self { $this->mapUrl = $mapUrl ?: null; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): self { $this->website = $website ?: null; return $this; }

    public function getLatitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $latitude): self { $this->latitude = $latitude; return $this; }

    public function getLongitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $longitude): self { $this->longitude = $longitude; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = $slug ?: null; return $this; }

    /** "Elbphilharmonie, Hamburg": the venue on a line of the list. */
    public function getLabel(): string
    {
        return implode(', ', array_filter([$this->name, $this->city]));
    }

    /** Everything on one line, for a calendar's LOCATION and a map search. */
    public function getFullAddress(): string
    {
        return implode(', ', array_filter([
            $this->name,
            $this->hall,
            $this->address,
            trim($this->postcode.' '.$this->city),
            $this->country,
        ]));
    }

    /** The map link typed in the back office, else a search of the address (or of the coordinates). */
    public function getMapLink(): ?string
    {
        if ($this->mapUrl) {
            return $this->mapUrl;
        }
        if (null !== $this->latitude && null !== $this->longitude) {
            return 'https://www.openstreetmap.org/?mlat='.$this->latitude.'&mlon='.$this->longitude.'#map=17/'.$this->latitude.'/'.$this->longitude;
        }
        $address = $this->getFullAddress();

        return '' !== $address ? 'https://www.openstreetmap.org/search?query='.rawurlencode($address) : null;
    }
}
