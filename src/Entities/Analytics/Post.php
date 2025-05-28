<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\PostRepository;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
#[ORM\Index(columns: ['postId'], name: 'postId_idx')]
#[ORM\HasLifecycleCallbacks]
class Post extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $postId;

    #[ORM\Column(type: 'json', nullable: true)]
    protected array $data = [];

    #[ORM\OneToMany(mappedBy: 'post', targetEntity: Metric::class, orphanRemoval: true)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
    }

    /**
     * Gets the platform-specific post ID.
     * @return string
     */
    public function getPostId(): string
    {
        return $this->postId;
    }

    /**
     * Sets the platform-specific post ID.
     * @param string $postId
     * @return self
     */
    public function addPostId(string $postId): self
    {
        $this->postId = $postId;
        return $this;
    }

    /**
     * Gets the post-specific data (e.g., caption, media_url, facebook_post_type).
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Sets the post-specific data.
     * @param array $data
     * @return self
     */
    public function addData(array $data): self
    {
        $this->data = $data;
        return $this;
    }
}