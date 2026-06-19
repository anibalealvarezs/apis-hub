<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledEvent;
use Entities\Entity;
use Repositories\EventRepository;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\HasLifecycleCallbacks]
class Event extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $name;

    #[ORM\OneToMany(mappedBy: 'event', targetEntity: ChanneledEvent::class, orphanRemoval: true)]
    protected Collection $channeledEvents;

    #[ORM\OneToMany(mappedBy: 'event', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->channeledEvents = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
    }

    /**
     * Gets the event name.
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the event name.
     * @param string $name
     * @return self
     */
    public function addName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Gets the collection of platform-specific events.
     * @return Collection
     */
    public function getChanneledEvents(): Collection
    {
        return $this->channeledEvents;
    }

    /**
     * Adds a platform-specific event.
     * @param ChanneledEvent $channeledEvent
     * @return self
     */
    public function addChanneledEvent(ChanneledEvent $channeledEvent): self
    {
        if (!$this->channeledEvents->contains($channeledEvent)) {
            $this->channeledEvents->add($channeledEvent);
            $channeledEvent->addEvent($this);
        }
        return $this;
    }

    /**
     * Removes a platform-specific event.
     * @param ChanneledEvent $channeledEvent
     * @return self
     */
    public function removeChanneledEvent(ChanneledEvent $channeledEvent): self
    {
        if ($this->channeledEvents->contains($channeledEvent)) {
            $this->channeledEvents->removeElement($channeledEvent);
            if ($channeledEvent->getEvent() === $this) {
                $channeledEvent->addEvent(null);
            }
        }
        return $this;
    }

    /**
     * Gets the collection of metric configs.
     * @return Collection
     */
    public function getMetricConfigs(): Collection
    {
        return $this->metricConfigs;
    }

    /**
     * Adds a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function addMetricConfig(MetricConfig $metricConfig): self
    {
        if (!$this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->add($metricConfig);
            $metricConfig->addEvent($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function removeMetricConfig(MetricConfig $metricConfig): self
    {
        if ($this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->removeElement($metricConfig);
            if ($metricConfig->getEvent() === $this) {
                $metricConfig->addEvent(null);
            }
        }
        return $this;
    }
}
