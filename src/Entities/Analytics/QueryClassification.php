<?php

namespace Entities\Analytics;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;

#[ORM\Entity]
#[ORM\Table(name: 'query_classifications')]
#[ORM\Index(name: 'idx_qc_intent_query', columns: ['intent', 'query_id'])]
#[ORM\Index(name: 'idx_qc_lang_query', columns: ['language', 'query_id'])]
class QueryClassification implements JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int $query_id;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    protected ?string $intent = null; // informational, commercial, transactional, navigational

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    protected ?string $language = null; // es, en, pt, fr, de, other

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $confidence = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $classified_at = null;

    public function getQueryId(): int
    {
        return $this->query_id;
    }

    public function setQueryId(int $query_id): self
    {
        $this->query_id = $query_id;
        return $this;
    }

    public function getIntent(): ?string
    {
        return $this->intent;
    }

    public function setIntent(?string $intent): self
    {
        $this->intent = $intent;
        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): self
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getClassifiedAt(): ?DateTime
    {
        return $this->classified_at;
    }

    public function setClassifiedAt(?DateTime $classified_at): self
    {
        $this->classified_at = $classified_at;
        return $this;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'query_id' => $this->query_id,
            'intent' => $this->intent,
            'language' => $this->language,
            'confidence' => $this->confidence,
            'classified_at' => $this->classified_at?->format('Y-m-d H:i:s'),
        ];
    }
}