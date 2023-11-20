<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledCustomer;
use Entities\Entity;
use Repositories\CustomerRepository;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
#[ORM\Index(columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class Customer extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $email;

    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: ChanneledCustomer::class, orphanRemoval: true)]
    protected Collection $channeledCustomers;

    public function __construct()
    {
        $this->channeledCustomers = new ArrayCollection();
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

    public function getChanneledCustomers(): ?Collection
    {
        return $this->channeledCustomers;
    }

    public function addChanneledCustomer(ChanneledCustomer $channeledCustomer): self
    {
        if (!$this->channeledCustomers->contains($channeledCustomer)) {
            $this->channeledCustomers->add($channeledCustomer);
            $channeledCustomer->addCustomer($this);
        }

        return $this;
    }

    public function addChanneledCustomers(Collection $channeledCustomers): self
    {
        foreach ($channeledCustomers as $channeledCustomer) {
            $this->addChanneledCustomer($channeledCustomer);
        }

        return $this;
    }

    public function removeChanneledCustomer(ChanneledCustomer $channeledCustomer): self
    {
        if ($this->channeledCustomers->contains($channeledCustomer)) {
            $this->channeledCustomers->removeElement($channeledCustomer);
            if ($channeledCustomer->getCustomer() === $this) {
                $channeledCustomer->addCustomer(null);
            }
        }

        return $this;
    }

    public function removeChanneledCustomers(Collection $channeledCustomers): self
    {
        foreach ($channeledCustomers as $channeledCustomer) {
            $this->removeChanneledCustomer($channeledCustomer);
        }

        return $this;
    }
}