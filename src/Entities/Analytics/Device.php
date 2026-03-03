<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Enums\Device as DeviceEnum;
use Repositories\DeviceRepository;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'devices')]
#[ORM\UniqueConstraint(name: 'device_type_unique', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
class Device extends Entity
{
    #[ORM\Column(type: 'string', enumType: DeviceEnum::class)]
    protected DeviceEnum $type;

    #[ORM\OneToMany(mappedBy: 'device', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->metricConfigs = new ArrayCollection();
    }

    public function addType(DeviceEnum $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): DeviceEnum
    {
        return $this->type;
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
            $metricConfig->addDevice($this);
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
            if ($metricConfig->getDevice() === $this) {
                $metricConfig->addDevice(null);
            }
        }
        return $this;
    }
}