<?php

namespace Entities\Analytics\Channeled;

use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledEntity;
use Repositories\Channeled\ChanneledSyncErrorRepository;

#[ORM\Entity(repositoryClass: ChanneledSyncErrorRepository::class)]
#[ORM\Table(name: 'channeled_sync_errors')]
#[ORM\Index(columns: ['channel', 'identifier'])]
#[ORM\Index(columns: ['created_at'])]
class ChanneledSyncError extends ChanneledEntity
{
    #[ORM\Column(type: 'string', length: 255)]
    protected string $identifier;

    #[ORM\Column(type: 'string', length: 50)]
    protected string $syncType; // e.g. 'metric', 'entity'

    #[ORM\Column(type: 'string', length: 50)]
    protected string $entityType; // e.g. 'campaign', 'ad', 'metric'

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $errorMessage = null;

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param string $identifier
     * @return self
     */
    public function addIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    /**
     * @return string
     */
    public function getSyncType(): string
    {
        return $this->syncType;
    }

    /**
     * @param string $syncType
     * @return self
     */
    public function addSyncType(string $syncType): self
    {
        $this->syncType = $syncType;
        return $this;
    }

    /**
     * @return string
     */
    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /**
     * @param string $entityType
     * @return self
     */
    public function addEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @param string|null $errorMessage
     * @return self
     */
    public function addErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }
}
