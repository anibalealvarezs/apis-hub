<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Customer;
use Repositories\Channeled\ChanneledCustomerRepository;

#[ORM\Entity(repositoryClass: ChanneledCustomerRepository::class)]
#[ORM\Table(name: 'channeled_customers')]
#[ORM\Index(columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledCustomer extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected int $email;

    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledCustomer', targetEntity: 'ChanneledOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $channeledOrders;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity:"\Entities\Analytics\Customer", inversedBy: 'channeledCustomers')]
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
     */
    public function addEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        $this->channeledOrders->add($channeledOrder);

        return $this;
    }

    public function addChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->addChanneledOrder($channeledOrder);
        }

        return $this;
    }

    public function removeChanneledOrder(ChanneledOrder $channeledOrder): void
    {
        $this->channeledOrders->removeElement($channeledOrder);
    }

    public function removeChanneledOrders(Collection $channeledOrders): void
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function addCustomer(Customer $customer): void
    {
        $this->customer = $customer;
    }
}