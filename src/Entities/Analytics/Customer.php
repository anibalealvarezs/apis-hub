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
#[ORM\Index(columns: ['email'], name: 'idx_customers_email_idx')]
#[ORM\HasLifecycleCallbacks]
class Customer extends Entity
{
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    protected string $email;

    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: ChanneledCustomer::class, orphanRemoval: true)]
    protected Collection $channeledCustomers;

    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->channeledCustomers = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
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
     * @return Customer
     */
    public function addEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledCustomers(): ?Collection
    {
        return $this->channeledCustomers;
    }

    /**
     * @param ChanneledCustomer $channeledCustomer
     * @return Customer
     */
    public function addChanneledCustomer(ChanneledCustomer $channeledCustomer): self
    {
        if ($this->channeledCustomers->contains($channeledCustomer)) {
            return $this;
        }

        $this->channeledCustomers->add($channeledCustomer);
        $channeledCustomer->addCustomer($this);

        return $this;
    }

    /**
     * @param Collection $channeledCustomers
     * @return Customer
     */
    public function addChanneledCustomers(Collection $channeledCustomers): self
    {
        foreach ($channeledCustomers as $channeledCustomer) {
            $this->addChanneledCustomer($channeledCustomer);
        }

        return $this;
    }

    /**
     * @param ChanneledCustomer $channeledCustomer
     * @return Customer
     */
    public function removeChanneledCustomer(ChanneledCustomer $channeledCustomer): self
    {
        if (!$this->channeledCustomers->contains($channeledCustomer)) {
            return $this;
        }

        $this->channeledCustomers->removeElement($channeledCustomer);

        if ($channeledCustomer->getCustomer() !== $this) {
            return $this;
        }

        $channeledCustomer->addCustomer(customer: null);

        return $this;
    }

    /**
     * @param Collection $channeledCustomers
     * @return Customer
     */
    public function removeChanneledCustomers(Collection $channeledCustomers): self
    {
        foreach ($channeledCustomers as $channeledCustomer) {
            $this->removeChanneledCustomer($channeledCustomer);
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
            $metricConfig->addCustomer($this);
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
            if ($metricConfig->getCustomer() === $this) {
                $metricConfig->addCustomer(null);
            }
        }
        return $this;
    }
}
