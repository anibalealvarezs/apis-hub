<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Customer;
use Repositories\Channeled\ChanneledCustomerRepository;

#[ORM\Entity(repositoryClass: ChanneledCustomerRepository::class)]
#[ORM\Table(name: 'channeled_customers')]
#[ORM\Index(columns: ['email', 'platformId', 'channel'], name: 'email_platformId_channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['email', 'channel'], name: 'email_channel_idx')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'platformCreatedAt_idx')]
#[ORM\Index(columns: ['email'], name: 'email_idx')]

#[ORM\HasLifecycleCallbacks]
class ChanneledCustomer extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $email;

    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledCustomer', targetEntity: 'ChanneledOrder', orphanRemoval: true)]
    protected Collection $channeledOrders;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'channeledCustomers')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Customer $customer;

    public function __construct()
    {
        $this->channeledOrders = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @param string $email
     * @return ChanneledCustomer
     */
    public function addEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if ($this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->add($channeledOrder);
        $channeledOrder->addChanneledCustomer($this);

        return $this;
    }

    public function addChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->addChanneledOrder($channeledOrder);
        }

        return $this;
    }

    public function removeChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if (!$this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->removeElement($channeledOrder);

        if ($channeledOrder->getChanneledCustomer() !== $this) {
            return $this;
        }

        $channeledOrder->addChanneledCustomer(channeledCustomer: null);

        return $this;
    }

    public function removeChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }

        return $this;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function addCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }
}