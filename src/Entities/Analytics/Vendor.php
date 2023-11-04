<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\Collection;
use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;
use Repositories\VendorRepository;

#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\Table(name: 'vendors')]
#[ORM\HasLifecycleCallbacks]
class Vendor extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    #[ORM\OneToMany(mappedBy: 'Vendor', targetEntity: 'Product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $products;

    /**
     * @return int|string
     */
    public function getPlatformId(): int|string
    {
        return $this->platformId;
    }

    /**
     * @param int|string $platformId
     */
    public function addPlatformId(int|string $platformId): void
    {
        $this->platformId = $platformId;
    }

    /**
     * @return string
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @param int $channel
     */
    public function addChannel(int $channel): void
    {
        $this->channel = $channel;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @param string $data
     */
    public function addData(string $data): void
    {
        $this->data = $data;
    }

    public function getProducts(): ?Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): self
    {
        $this->products->add($product);

        return $this;
    }

    public function addProducts(Collection $products): self
    {
        foreach ($products as $product) {
            $this->addProduct($product);
        }

        return $this;
    }

    public function removeProduct(Product $product): void
    {
        $this->products->removeElement($product);
    }

    public function removeProducts(Collection $products): void
    {
        foreach ($products as $product) {
            $this->removeProduct($product);
        }
    }
}