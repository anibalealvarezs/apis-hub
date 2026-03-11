<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;
use Repositories\JobRepository;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(name: 'jobs')]
#[ORM\Index(columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Job extends Entity
{
    #[ORM\Column(type: 'integer')]
    protected int $status;

    #[ORM\Column(type: 'string')]
    protected string $uuid;

    #[ORM\Column(type: 'string')]
    protected string $entity;

    #[ORM\Column(type: 'string')]
    protected string $channel;

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @param int $status
     */
    public function addStatus(int $status): void
    {
        $this->status = $status;
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @param string $uuid
     */
    public function addUuid(string $uuid): void
    {
        $this->uuid = $uuid;
    }

    /**
     * @return string
     */
    public function getEntity(): string
    {
        return $this->entity;
    }

    /**
     * @param string $entity
     */
    public function addEntity(string $entity): void
    {
        $this->entity = $entity;
    }

    /**
     * @return string
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @param string $channel
     */
    public function addChannel(string $channel): void
    {
        $this->channel = $channel;
    }

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $payload = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $message = null;

    /**
     * @return array|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array|null $payload
     */
    public function addPayload(?array $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @param string|null $message
     */
    public function addMessage(?string $message): void
    {
        $this->message = $message;
    }
}
