<?php

declare(strict_types=1);

namespace Entities;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

class Entity
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    protected int $id;

    #[ORM\Column(name: 'created_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected DateTime $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected DateTime $updatedAt;

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return DateTime|null
     */
    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    /**
     * @param DateTime $createdAt
     * @return void
     */
    public function addCreatedAt(DateTime $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    /**
     * @return DateTime|null
     */
    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    /**
     * @param DateTime $updatedAt
     * @return void
     */
    public function addUpdatedAt(DateTime $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTime(datetime: 'now');
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (isset($this->createdAt)) {
            return;
        }

        $this->createdAt = new DateTime(datetime: 'now');
        $this->updatedAt = new DateTime(datetime: 'now');
    }
}
