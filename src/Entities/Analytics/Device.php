<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Anibalealvarezs\ApiSkeleton\Enums\Device as DeviceEnum;
use Repositories\DeviceRepository;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'devices')]
#[ORM\UniqueConstraint(name: 'device_type_unique', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
class Device extends Entity
{
    #[ORM\Column(type: 'string', enumType: DeviceEnum::class)]
    protected DeviceEnum $type;

    public function __construct()
    {
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
}
