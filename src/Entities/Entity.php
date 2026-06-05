<?php

declare(strict_types=1);

namespace Entities;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use ReflectionClass;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class Entity implements JsonSerializable
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected ?int $id = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected DateTime $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected DateTime $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    protected int $version = 0;

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

    /**
     * @return int
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return void
     */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTime(datetime: 'now');
    }

    /**
     * @return void
     */
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (isset($this->createdAt)) {
            return;
        }

        $this->createdAt = new DateTime(datetime: 'now');
        $this->updatedAt = new DateTime(datetime: 'now');
    }

    public function jsonSerialize(): mixed
    {
        $data = [];
        $reflection = new ReflectionClass($this);
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            // Skip doctrine proxy properties
            if (str_starts_with($name, '__')) continue;
            
            // Allow access and get value
            if (!$property->isInitialized($this)) {
                $data[$name] = null;
                continue;
            }
            
            $value = $property->getValue($this);
            
            // Format dates
            if ($value instanceof DateTime) {
                $data[$name] = $value->format('Y-m-d H:i:s');
            } 
            // Handle collections or relations by ID
            elseif (is_object($value)) {
                if (method_exists($value, 'getId')) {
                    $data[$name . '_id'] = $value->getId();
                } else {
                    // Skip full serialization of relations to prevent infinite loops
                    $data[$name] = null;
                }
            } else {
                $data[$name] = $value;
            }
        }
        return $data;
    }
}
