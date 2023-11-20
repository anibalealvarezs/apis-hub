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
    protected string $filename;

    /**
     * @return string
     */
    public function getStatus(): string
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
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * @param string $filename
     */
    public function addFilename(string $filename): void
    {
        $this->filename = $filename;
    }
}
