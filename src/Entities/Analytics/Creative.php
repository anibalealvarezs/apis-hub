<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Entity;
use Repositories\CreativeRepository;

#[ORM\Entity(repositoryClass: CreativeRepository::class)]
#[ORM\Table(name: 'creatives')]
#[ORM\Index(columns: ['creativeId'], name: 'creativeId_idx')]
#[ORM\HasLifecycleCallbacks]
class Creative extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $creativeId;

    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\OneToMany(mappedBy: 'creative', targetEntity: ChanneledAd::class)]
    protected Collection $channeledAds;

    public function __construct()
    {
        $this->channeledAds = new ArrayCollection();
    }

    /**
     * Gets the unique creative identifier.
     * @return string
     */
    public function getCreativeId(): string
    {
        return $this->creativeId;
    }

    /**
     * Sets the unique creative identifier.
     * @param string $creativeId
     * @return self
     */
    public function addCreativeId(string $creativeId): self
    {
        $this->creativeId = $creativeId;
        return $this;
    }

    /**
     * Gets the creative name.
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the creative name.
     * @param string $name
     * @return self
     */
    public function addName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Gets the creative data (e.g., image_url, headline).
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Sets the creative data.
     * @param array|null $data
     * @return self
     */
    public function addData(?array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Gets the collection of platform-specific ad groups.
     * @return Collection
     */
    public function getChanneledAds(): Collection
    {
        return $this->channeledAds;
    }

    /**
     * Adds a platform-specific ad group.
     * @param ChanneledAd $channeledAd
     * @return self
     */
    public function addChanneledAd(ChanneledAd $channeledAd): self
    {
        if (!$this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->add($channeledAd);
            $channeledAd->addCreative($this);
        }
        return $this;
    }

    /**
     * Removes a platform-specific ad group.
     * @param ChanneledAd $channeledAd
     * @return self
     */
    public function removeChanneledAd(ChanneledAd $channeledAd): self
    {
        if ($this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->removeElement($channeledAd);
            if ($channeledAd->getCreative() === $this) {
                $channeledAd->addCreative(null);
            }
        }
        return $this;
    }
}