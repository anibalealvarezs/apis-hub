<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Event;
use Entities\Analytics\MetricConfig;
use Repositories\Channeled\ChanneledEventRepository;

#[ORM\Entity(repositoryClass: ChanneledEventRepository::class)]
#[ORM\Table(name: 'channeled_events')]
#[ORM\Index(name: 'idx_channeled_events_platform_id_idx', columns: ['platform_id'])]
#[ORM\Index(name: 'idx_channeled_events_channel_idx', columns: ['channel'])]
#[ORM\Index(name: 'idx_channeled_events_platform_channel_idx', columns: ['platform_id', 'channel'])]
#[ORM\Index(name: 'idx_channeled_events_channeled_account_id_idx', columns: ['channeled_account_id'])]
#[ORM\Index(name: 'idx_channeled_events_event_idx', columns: ['event_id'])]
#[ORM\Index(name: 'idx_channeled_events_channeled_account_id_event_id_idx', columns: ['channeled_account_id', 'event_id'])]
#[ORM\UniqueConstraint(name: 'channeled_events_platform_id_account_id_uidx', columns: ['platform_id', 'channeled_account_id'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledEvent extends ChanneledEntity
{
    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'channeledEvents')]
    #[ORM\JoinColumn(name: 'event_id', onDelete: 'CASCADE')]
    protected ?Event $event = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAccount::class)]
    #[ORM\JoinColumn(name: 'channeled_account_id', onDelete: 'CASCADE')]
    protected ChanneledAccount $channeledAccount;

    #[ORM\OneToMany(mappedBy: 'channeledEvent', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->metricConfigs = new ArrayCollection();
    }

    /**
     * Gets the associated global event.
     * @return Event|null
     */
    public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * Sets the associated global event.
     * @param Event|null $event
     * @return self
     */
    public function addEvent(?Event $event): self
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Gets the associated channeled account.
     * @return ChanneledAccount
     */
    public function getChanneledAccount(): ChanneledAccount
    {
        return $this->channeledAccount;
    }

    /**
     * Sets the associated channeled account.
     * @param ChanneledAccount|null $channeledAccount
     * @return self
     */
    public function addChanneledAccount(?ChanneledAccount $channeledAccount): self
    {
        $this->channeledAccount = $channeledAccount;

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
        if (! $this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->add($metricConfig);
            $metricConfig->addChanneledEvent($this);
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
            if ($metricConfig->getChanneledEvent() === $this) {
                $metricConfig->addChanneledEvent(null);
            }
        }

        return $this;
    }
}
