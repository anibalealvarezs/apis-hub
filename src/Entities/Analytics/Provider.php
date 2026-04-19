<?php

declare(strict_types=1);

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;

#[ORM\Entity]
#[ORM\Table(name: 'providers')]
class Provider extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $name;

    #[ORM\Column(type: 'string')]
    protected string $label;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $icon = null;

    #[ORM\OneToMany(mappedBy: 'provider', targetEntity: Channel::class, cascade: ['persist', 'remove'])]
    protected Collection $channels;

    public function __construct()
    {
        $this->channels = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function addChannel(Channel $channel): self
    {
        if (!$this->channels->contains($channel)) {
            $this->channels->add($channel);
            $channel->setProvider($this);
        }
        return $this;
    }
}
