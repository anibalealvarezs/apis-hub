<?php

namespace Entities\Analytics;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;

#[ORM\Entity]
#[ORM\Table(name: 'account_query_classifications')]
#[ORM\Index(name: 'idx_aqc_asset_lookup', columns: ['channeled_account_id', 'query_id'])]
#[ORM\Index(name: 'idx_aqc_brand_filter', columns: ['channeled_account_id', 'brand_relation', 'query_id'])]
#[ORM\Index(name: 'idx_aqc_relevance_filter', columns: ['channeled_account_id', 'business_relevance', 'query_id'])]
class AccountQueryClassification implements JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int $channeled_account_id;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int $query_id;

    #[ORM\Column(type: 'string', length: 32)]
    protected string $brand_relation; // brand, non_brand, competitor

    #[ORM\Column(type: 'string', length: 32)]
    protected string $business_relevance; // core, adjacent, irrelevant

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $confidence = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $classified_at = null;

    public function getChanneledAccountId(): int
    {
        return $this->channeled_account_id;
    }

    public function setChanneledAccountId(int $channeled_account_id): self
    {
        $this->channeled_account_id = $channeled_account_id;
        return $this;
    }

    public function getQueryId(): int
    {
        return $this->query_id;
    }

    public function setQueryId(int $query_id): self
    {
        $this->query_id = $query_id;
        return $this;
    }

    public function getBrandRelation(): string
    {
        return $this->brand_relation;
    }

    public function setBrandRelation(string $brand_relation): self
    {
        $this->brand_relation = $brand_relation;
        return $this;
    }

    public function getBusinessRelevance(): string
    {
        return $this->business_relevance;
    }

    public function setBusinessRelevance(string $business_relevance): self
    {
        $this->business_relevance = $business_relevance;
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
            'channeled_account_id' => $this->channeled_account_id,
            'query_id' => $this->query_id,
            'brand_relation' => $this->brand_relation,
            'business_relevance' => $this->business_relevance,
            'confidence' => $this->confidence,
            'classified_at' => $this->classified_at?->format('Y-m-d H:i:s'),
        ];
    }
}