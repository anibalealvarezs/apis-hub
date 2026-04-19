<?php

declare(strict_types=1);

namespace Entities\Analytics;

use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;

#[ORM\Entity]
#[ORM\Table(name: 'channels')]
class Channel extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $name;

    #[ORM\Column(type: 'string')]
    protected string $label;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $icon = null;

    #[ORM\Column(type: 'integer', options: ['default' => 600])]
    protected int $cooldown = 600;

    #[ORM\ManyToOne(targetEntity: Provider::class, inversedBy: 'channels')]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Provider $provider;

    public function getCooldown(): int
    {
        return $this->cooldown;
    }

    public function setCooldown(int $cooldown): self
    {
        $this->cooldown = $cooldown;
        return $this;
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

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function setProvider(Provider $provider): self
    {
        $this->provider = $provider;
        return $this;
    }
}
