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

    #[ORM\OneToMany(mappedBy: 'channeledCustomer', targetEntity: ChanneledOrder::class, orphanRemoval: true)]
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
     * @return static
     */
    public function addEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    /**
     * @param ChanneledOrder $channeledOrder
     * @return static
     */
    public function addChanneledOrder(ChanneledOrder $channeledOrder): static
    {
        if ($this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->add($channeledOrder);
        $channeledOrder->addChanneledCustomer($this);

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return static
     */
    public function addChanneledOrders(Collection $channeledOrders): static
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->addChanneledOrder($channeledOrder);
        }

        return $this;
    }

    /**
     * @param ChanneledOrder $channeledOrder
     * @return static
     */
    public function removeChanneledOrder(ChanneledOrder $channeledOrder): static
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

    /**
     * @param Collection $channeledOrders
     * @return static
     */
    public function removeChanneledOrders(Collection $channeledOrders): static
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }

        return $this;
    }

    /**
     * @return Customer
     */
    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /**
     * @param Customer|null $customer
     * @return static
     */
    public function addCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }
}
